<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('initiate persists the full server-built session shape from UploadInitData', function (): void {
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $folder = new Node;
    $folder->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'inbox',
        'size_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $folder->save());

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::for(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 42])), correlationId: (string) Str::ulid()), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $folder,
        targetName: 'shape.bin',
        expectedSha256: str_repeat('a', 64),
        totalSizeBytes: 128,
        mimeType: 'application/octet-stream',
        metadata: ['filename' => 'shape.bin'],
    ));

    expect([
        'tenant_id' => $session->tenant_id,
        'drive_id' => $session->drive_id,
        'node_id' => $session->target_node_id,
        'target_parent_id' => $session->target_parent_id,
        'target_name' => $session->target_name,
        'protocol' => $session->protocol,
        'status' => $session->status,
        'expected_sha256' => $session->expected_sha256,
        'total_size_bytes' => $session->total_size_bytes,
        'received_bytes' => $session->received_bytes,
        'chunk_size' => $session->chunk_size,
        'mime_type' => $session->mime_type,
        'metadata' => $session->metadata,
        'created_by' => $session->created_by,
    ])->toBe([
        'tenant_id' => $storage->tenant_id,
        'drive_id' => $storage->getKey(),
        'node_id' => null,
        'target_parent_id' => $folder->getKey(),
        'target_name' => 'shape.bin',
        'protocol' => UploadProtocol::Tus,
        'status' => UploadStatus::Initiated,
        'expected_sha256' => str_repeat('a', 64),
        'total_size_bytes' => 128,
        'received_bytes' => 0,
        'chunk_size' => app(DriveConfig::class)->chunkSize(),
        'mime_type' => 'application/octet-stream',
        'metadata' => ['filename' => 'shape.bin', 'actor_type' => TestUser::class, 'expected_sequence' => null],
        'created_by' => '42',
    ])
        ->and($session->upload_id)->not->toBeEmpty()
        ->and($session->expires_at)->not->toBeNull();
});

it('initiate stores server owner identity even when client metadata is empty', function (): void {
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'empty-meta.bin',
        totalSizeBytes: 4,
        metadata: [],
    ));

    expect($session->metadata)->toBe(['actor_type' => 'vaulter:system', 'expected_sequence' => null]);
});

it('initiate leaves a tus session ready for PATCH after handler initialise', function (): void {
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'init.bin',
        totalSizeBytes: 4,
    ));

    expect($session->status)->toBe(UploadStatus::Initiated)
        ->and(app(StagedUpload::class)->size($session))->toBe(0);
});

it('markAssembled refuses an illegal status transition', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Committed])->save());

    expect(fn () => app(UploadSessionManager::class)->markAssembled($session->refresh()))
        ->toThrow(ValidationException::class);
});

it('accepts initiate total_size_bytes exactly on max_bytes and rejects one byte over', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 10);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    $exact = $manager->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: 'exact.bin',
        totalSizeBytes: 10,
    ));

    expect($exact->total_size_bytes)->toBe(10);

    expect(fn () => $manager->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: 'over.bin',
        totalSizeBytes: 11,
    )))->toThrow(ValidationException::class);
});

it('active-session cap allows max minus one and rejects at the ceiling', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 2);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);
    $data = fn (string $name): InitiateUploadInput => new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: $name,
        totalSizeBytes: 4,
    );

    $manager->initiate(context: DriveContext::for(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 9001])), correlationId: 'cap-test'), data: $data('one.bin'));
    $manager->initiate(context: DriveContext::for(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 9001])), correlationId: 'cap-test'), data: $data('two.bin'));

    expect(fn () => $manager->initiate(context: DriveContext::for(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 9001])), correlationId: 'cap-test'), data: $data('three.bin')))
        ->toThrow(TooManyRequestsHttpException::class);
});

it('countActiveForActor counts only initiated and uploading rows', function (): void {
    $storage = UploadTestHelpers::storage();
    $read = app(UploadSessionReadRepository::class);

    UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    $uploading = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    app(VaulterDatabase::class)->write(fn () => $uploading->forceFill(['status' => UploadStatus::Uploading])->save());
    $aborted = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    app(VaulterDatabase::class)->write(fn () => $aborted->forceFill(['status' => UploadStatus::Aborted])->save());

    expect($read->countActiveForActor(55))->toBe(2)
        ->and($read->countActiveForActor(null))->toBe(0);
});

it('UploadPartStoreRepository replaces part fields on re-upload of the same part_no', function (): void {
    $session = UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 20,
    );
    $parts = app(UploadPartStoreRepository::class);

    $firstHash = hash('sha256', 'part-a');
    $secondHash = hash('sha256', 'part-b');
    $first = $parts->record($session, partNo: 1, byteOffset: 0, sizeBytes: 5, etag: 'etag-a', partSha256: $firstHash);
    $second = $parts->record($session, partNo: 1, byteOffset: 0, sizeBytes: 7, etag: 'etag-b', partSha256: $secondHash);

    expect(UploadPart::query()->where('upload_session_id', $session->getKey())->count())->toBe(1)
        ->and($second->getKey())->toEqual($first->getKey())
        ->and($second->size_bytes)->toBe(7)
        ->and($second->etag)->toBe('etag-b')
        ->and($second->part_sha256)->toBe($secondHash);
});

it('commit commits PNG bytes when declared mime matches and discards staged temp afterward', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAD0lEQVR42mP8z5BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: strlen($png), parentId: $storage->root_node_id);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['mime_type' => 'image/png'])->save());

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($png), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    expect($node->kind)->toBe(NodeKind::File)
        ->and(Blob::query()->count())->toBe(1)
        ->and(app(StagedUpload::class)->size($session->refresh()))->toBe(0)
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed)
        ->and($session->refresh()->assembled_blob_id)->not->toBeNull();
});

it('commit places a new file under the session target_parent_id, not implicit root', function (): void {
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $folder = new Node;
    $folder->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'dest',
        'size_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $folder->save());

    $bytes = 'folder-target';
    $session = UploadTestHelpers::session(
        $storage,
        totalSizeBytes: strlen($bytes),
        targetName: 'nested.bin',
        parentId: $folder->getKey(),
    );

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    expect($node->parent_id)->toEqual($folder->getKey())
        ->and($node->parent_id)->not->toEqual($root->getKey());
});

it('commit fails loud when the session storage row is missing', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 4, parentId: $storage->root_node_id);
    app(StagedUpload::class)->write($session, 'data');
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    $missingStorageId = 9_999_999;

    if (DB::connection()->getDriverName() === 'pgsql') {
        $table = $session->getTable();
        DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.$table.'_drive_id_foreign"');
    }

    UploadSession::query()->whereKey($session->getKey())->update(['drive_id' => $missingStorageId]);
    $session = $session->fresh();

    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($storage, 'test fixture'), session: $session))
        ->toThrow(NodeNotFoundException::class);
});

it('commit rejects image/jpg declared when magic bytes are PNG', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAD0lEQVR42mP8z5BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: strlen($png));
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['mime_type' => 'image/jpg'])->save());

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($png), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh()))
        ->toThrow(UploadContentMismatchException::class);

    expect(Blob::query()->count())->toBe(0);
});

it('advanceReceived flips initiated to uploading on first chunk', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);

    app(UploadSessionStoreRepository::class)->advanceReceived($session, 3);

    expect($session->refresh()->status)->toBe(UploadStatus::Uploading)
        ->and($session->received_bytes)->toBe(3);
});
