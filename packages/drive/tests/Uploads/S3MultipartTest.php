<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevision;

/**
 * The S3-multipart path exercised against the FAKED 'local' disk (a real S3 is not
 * required — the handler proxies parts through the StorageDriver, so the same flow
 * holds; a host swaps the handler for a presigned-PUT one in production). The H.3
 * invariant — assemble → server-recompute sha256 → BlobStore.commit — is verified
 * identically to the tus path.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

function initiateS3(int $totalBytes, string $name = 'multi.bin'): UploadSession
{
    $storage = UploadTestHelpers::storage();

    return app(UploadSessionManager::class)->initiate(new InitiateUploadInput(
        storage: $storage,
        protocol: UploadProtocol::S3Multipart,
        targetParent: $storage->rootNode()->first(),
        targetName: $name,
        totalSizeBytes: $totalBytes,
        mimeType: 'application/octet-stream',
    ));
}

it('initiate → parts → complete → server-recompute → commit', function (): void {
    $part1 = str_repeat('A', 12);
    $part2 = str_repeat('B', 8);
    $whole = $part1.$part2; // 20 bytes

    $session = initiateS3(totalBytes: strlen($whole), name: 'multi.bin');
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($part1));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: 12, chunk: UploadTestHelpers::streamFor($part2));

    // Both parts recorded; received_bytes reflects the sum.
    expect(UploadPart::query()->where('upload_session_id', $session->getKey())->count())->toBe(2)
        ->and($session->refresh()->received_bytes)->toBe(20);

    $handler->complete($session->refresh());

    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute($session->refresh());

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->size_bytes)->toBe(20)
        ->and(Blob::query()->count())->toBe(1);

    // The committed content hash is recomputed from the STITCHED bytes, not any ETag.
    $version = $node->currentVersion()->first();
    expect($version->sha256)->toBe(hash('sha256', $whole))
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed);
});

it('re-upload части ЗАМЕНЯЕТ (PK part_no) — S3 семантика', function (): void {
    $session = initiateS3(totalBytes: 5, name: 'replace.bin');
    $handler = app(S3MultipartHandler::class);

    // Upload part 1, then re-upload it with different bytes.
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('aaaaa'));
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('bbbbb'));

    // Still ONE row for part 1 — the re-upload replaced it.
    expect(UploadPart::query()->where('upload_session_id', $session->getKey())->count())->toBe(1);

    $handler->complete($session->refresh());
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute($session->refresh());

    // The committed bytes are the REPLACEMENT (bbbbb), not the original.
    $version = $node->currentVersion()->first();
    expect($version->sha256)->toBe(hash('sha256', 'bbbbb'));
});

it('лживый ETag не используется как адрес — сервер пересчитывает sha256', function (): void {
    $bytes = 'real multipart bytes';
    $session = initiateS3(totalBytes: strlen($bytes), name: 'x.bin');
    $handler = app(S3MultipartHandler::class);

    $result = $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($bytes));

    // The handler returns an etag (the object-store handle) but the COMMITTED hash is
    // the server-recomputed content sha256 — they are unrelated.
    $handler->complete($session->refresh());
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute($session->refresh());

    $version = $node->currentVersion()->first();
    expect($version->sha256)->toBe(hash('sha256', $bytes))
        ->and($version->sha256)->not->toBe(trim($result['etag'], '"'));
});

it('complete без частей отклоняется', function (): void {
    $session = initiateS3(totalBytes: 10);

    expect(fn () => app(S3MultipartHandler::class)->complete($session->refresh()))
        ->toThrow(ValidationException::class);
});

it('streams multipart assembly with bounded complete memory', function (): void {
    $partBytes = 1024 * 1024;
    $partCount = 32;
    $session = initiateS3(totalBytes: $partBytes * $partCount, name: 'bounded.bin');
    $handler = app(S3MultipartHandler::class);
    $part = str_repeat('x', $partBytes);

    foreach (range(1, $partCount) as $partNo) {
        $handler->putPart(
            $session->refresh(),
            partNo: $partNo,
            byteOffset: ($partNo - 1) * $partBytes,
            chunk: UploadTestHelpers::streamFor($part),
        );
    }

    unset($part);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    expect($handler->complete($session->refresh()))->toBe($partBytes * $partCount)
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(16 * 1024 * 1024)
        ->and(app(StagedUpload::class)->size($session->refresh()))
        ->toBe($partBytes * $partCount);
});

it('abort удаляет per-part объекты и помечает aborted', function (): void {
    $session = initiateS3(totalBytes: 10, name: 'abort.bin');
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('1234567890'));

    Storage::disk('local')->assertExists("tmp/uploads/{$session->upload_id}.parts/1");

    app(UploadSessionManager::class)->abort($session->refresh());

    Storage::disk('local')->assertMissing("tmp/uploads/{$session->upload_id}.parts/1");
    expect($session->refresh()->status)->toBe(UploadStatus::Aborted);
});

it('дедуп: multipart-сборка против идентичной прежней загрузки → один блоб (global)', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    $bytes = 'shared multipart payload bytes';

    foreach (['a.bin', 'b.bin'] as $name) {
        $session = initiateS3(totalBytes: strlen($bytes), name: $name);
        $handler = app(S3MultipartHandler::class);
        $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($bytes));
        $handler->complete($session->refresh());
        app(UploadSessionManager::class)->markAssembled($session->refresh());
        app(CompleteUploadAction::class)->execute($session->refresh());
    }

    // Two file nodes + two versions, but ONE physical blob (global dedup).
    expect(FileRevision::query()->count())->toBe(2)
        ->and(Blob::query()->count())->toBe(1);
});
