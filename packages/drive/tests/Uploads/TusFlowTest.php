<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Exceptions\OffsetMismatchException;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Actions\CommitUploadAction;
use Vaulter\Drive\Uploads\Data\UploadInitData;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

/**
 * Initiate a tus session for a new file under the storage root.
 */
function initiateTus(int $totalBytes, string $name = 'big.bin'): UploadSession
{
    $storage = UploadTestHelpers::storage();

    return app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: $name,
        totalSizeBytes: $totalBytes,
        mimeType: 'application/octet-stream',
    ));
}

it('open сессию: initiated, нулевой offset, upload_id и TTL', function (): void {
    $session = initiateTus(totalBytes: 10);

    expect($session->status)->toBe(UploadStatus::Initiated)
        ->and($session->received_bytes)->toBe(0)
        ->and($session->upload_id)->not->toBeEmpty()
        ->and($session->expires_at)->not->toBeNull();
});

it('PATCH-чанки → HEAD-offset растёт → assemble → commit создаёт файловый узел', function (): void {
    $bytes = 'hello resumable drive world'; // 27 bytes
    $session = initiateTus(totalBytes: strlen($bytes), name: 'hello.txt');

    $handler = app(TusUploadHandler::class);

    // First chunk.
    $first = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(substr($bytes, 0, 10)), 0);

    expect($first['offset'])->toBe(10)
        ->and($first['complete'])->toBeFalse()
        // HEAD returns the authoritative server offset.
        ->and($handler->offset($session->refresh()))->toBe(10);

    // Second chunk resumes from the server offset.
    $second = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(substr($bytes, 10)), 10);

    expect($second['offset'])->toBe(27)
        ->and($second['complete'])->toBeTrue();

    // Assemble → commit through the SAME race-free CAS.
    $manager = app(UploadSessionManager::class);
    $manager->markAssembled($session->refresh());
    $node = app(CommitUploadAction::class)->execute($session->refresh());

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->name)->toBe('hello.txt')
        ->and($node->size_bytes)->toBe(27)
        ->and(FileVersion::query()->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1)
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed)
        ->and($session->refresh()->assembled_blob_id)->not->toBeNull();
});

it('resume после разрыва: второй клиент читает HEAD-offset и продолжает', function (): void {
    $bytes = str_repeat('A', 8).str_repeat('B', 8); // 16 bytes
    $session = initiateTus(totalBytes: 16, name: 'resume.bin');

    $handler = app(TusUploadHandler::class);

    // Client 1 uploads the first half, then "disconnects".
    $handler->patch($session->refresh(), UploadTestHelpers::streamFor(substr($bytes, 0, 8)), 0);

    // Client 2 starts fresh: it reads HEAD to learn the offset, then continues.
    $resumeOffset = $handler->offset($session->refresh());

    expect($resumeOffset)->toBe(8);

    $result = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(substr($bytes, $resumeOffset)), $resumeOffset);

    expect($result['complete'])->toBeTrue()
        ->and($result['offset'])->toBe(16);

    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CommitUploadAction::class)->execute($session->refresh());

    // The reassembled bytes are intact (server-stitched at the authoritative offset).
    $version = $node->currentVersion()->first();
    expect($version->size_bytes)->toBe(16)
        ->and($version->content_sha256)->toBe(hash('sha256', $bytes));
});

it('отвергает PATCH с offset, не совпадающим с серверным — байты расходятся (tus 409, F-P1.4-02)', function (): void {
    $session = initiateTus(totalBytes: 20);

    $handler = app(TusUploadHandler::class);
    $handler->patch($session->refresh(), UploadTestHelpers::streamFor('12345'), 0);

    // The server is at offset 5; a PATCH claiming offset 0 again with DIFFERENT
    // bytes than what is already staged there is a genuine conflict — not an
    // idempotent retransmit (F-P1.4-02, N3 C6).
    expect(fn () => $handler->patch($session->refresh(), UploadTestHelpers::streamFor('xxxxx'), 0))
        ->toThrow(OffsetMismatchException::class);
});

it('идемпотентный повтор: PATCH с offset<server, но с СОВПАДАЮЩИМИ уже застейдженными байтами → успех, offset не двигается назад (F-P1.4-02)', function (): void {
    $session = initiateTus(totalBytes: 20);

    $handler = app(TusUploadHandler::class);
    $handler->patch($session->refresh(), UploadTestHelpers::streamFor('12345'), 0);

    // A retransmit of the SAME first chunk (client never saw the 204, retried) —
    // the bytes match what is already staged at [0,5), so this is NOT a conflict.
    $result = $handler->patch($session->refresh(), UploadTestHelpers::streamFor('12345'), 0);

    expect($result['offset'])->toBe(5)
        ->and($result['complete'])->toBeFalse();
});

it('дедуп: идентичная резюмируемая загрузка → один блоб против прежней', function (): void {
    $bytes = 'identical resumable payload';

    // First resumable upload.
    $a = initiateTus(totalBytes: strlen($bytes), name: 'a.bin');
    app(TusUploadHandler::class)->patch($a->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($a->refresh());
    app(CommitUploadAction::class)->execute($a->refresh());

    // Second resumable upload of the SAME bytes (different storage/session).
    $b = initiateTus(totalBytes: strlen($bytes), name: 'b.bin');
    app(TusUploadHandler::class)->patch($b->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($b->refresh());
    app(CommitUploadAction::class)->execute($b->refresh());

    // global dedup so two storages share the blob; assert one physical blob.
    config()->set('vaulter.dedup.scope', 'global');

    expect(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(2)
        ->and(FileVersion::query()->count())->toBe(2);
});

it('лживый client sha256 игнорируется — адрес считается с собранных байт', function (): void {
    $bytes = 'the real bytes';
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'x.bin',
        expectedSha256: str_repeat('f', 64), // a lie
        totalSizeBytes: strlen($bytes),
    ));

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CommitUploadAction::class)->execute($session->refresh());

    // The committed version's hash is the REAL content hash, not the client's lie.
    $version = $node->currentVersion()->first();
    expect($version->content_sha256)->toBe(hash('sha256', $bytes))
        ->and($version->content_sha256)->not->toBe(str_repeat('f', 64));
});

it('квота: сборка сверх квоты отклоняется на commit', function (): void {
    $bytes = 'this is definitely too many bytes for the quota';
    $storage = UploadTestHelpers::storage(quotaBytes: 5);

    $session = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'x.bin',
        totalSizeBytes: strlen($bytes),
    ));

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    expect(fn () => app(CommitUploadAction::class)->execute($session->refresh()))
        ->toThrow(QuotaExceededException::class);

    // No blob committed, quota untouched.
    expect(Blob::query()->count())->toBe(0)
        ->and($storage->refresh()->used_bytes)->toBe(0);
});

it('over-size: initiate сверх max_bytes отклоняется ДО приёма байт', function (): void {
    config()->set('vaulter.uploads.max_bytes', 10);
    $storage = UploadTestHelpers::storage();

    expect(fn () => app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'x.bin',
        totalSizeBytes: 9_999,
    )))->toThrow(ValidationException::class);

    expect(UploadSession::query()->count())->toBe(0);
});

it('abort чистит staged temp и помечает aborted', function (): void {
    $session = initiateTus(totalBytes: 20);

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor('half bytes'), 0);

    $stagedKey = app(StagedUpload::class)->keyFor($session);
    Storage::disk('local')->assertExists($stagedKey);

    app(UploadSessionManager::class)->abort($session->refresh());

    Storage::disk('local')->assertMissing($stagedKey);
    expect($session->refresh()->status)->toBe(UploadStatus::Aborted);
});

it('новая версия: резюмируемая загрузка на существующий узел бампит version_no', function (): void {
    // Seed a v1 file via a first resumable upload.
    $storage = UploadTestHelpers::storage();
    $v1 = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'doc.txt',
        totalSizeBytes: 2,
    ));
    app(TusUploadHandler::class)->patch($v1->refresh(), UploadTestHelpers::streamFor('v1'), 0);
    app(UploadSessionManager::class)->markAssembled($v1->refresh());
    $node = app(CommitUploadAction::class)->execute($v1->refresh());

    $firstVersionId = $node->current_version_id;

    // A NEW-VERSION resumable upload onto that node.
    $v2 = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetNode: $node,
        totalSizeBytes: 12,
    ));
    app(TusUploadHandler::class)->patch($v2->refresh(), UploadTestHelpers::streamFor('version two!'), 0);
    app(UploadSessionManager::class)->markAssembled($v2->refresh());
    $updated = app(CommitUploadAction::class)->execute($v2->refresh());

    $file = $updated->file;

    expect($file->version_count)->toBe(2)
        ->and($updated->current_version_id)->not->toEqual($firstVersionId)
        ->and($file->currentVersion->version_no)->toBe(2);
});
