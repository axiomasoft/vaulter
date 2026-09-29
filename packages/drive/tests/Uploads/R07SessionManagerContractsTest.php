<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Vaulter\Drive\Actions\Uploads\CommitUploadAction;
use Vaulter\Drive\Data\Uploads\UploadInitData;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\UploadContentMismatchException;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Support\Uploads\StagedUpload;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('initiate persists the full server-built session shape from UploadInitData', function (): void {
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $folder = new Node;
    $folder->forceFill([
        'storage_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'inbox',
        'size_bytes' => 0,
    ]);
    $folder->save();

    $session = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $folder,
        targetName: 'shape.bin',
        expectedSha256: str_repeat('a', 64),
        totalSizeBytes: 128,
        mimeType: 'application/octet-stream',
        metadata: ['filename' => 'shape.bin'],
        actorId: 42,
    ));

    expect([
        'tenant_id' => $session->tenant_id,
        'storage_id' => $session->storage_id,
        'node_id' => $session->node_id,
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
        'storage_id' => $storage->getKey(),
        'node_id' => null,
        'target_parent_id' => $folder->getKey(),
        'target_name' => 'shape.bin',
        'protocol' => UploadProtocol::Tus,
        'status' => UploadStatus::Initiated,
        'expected_sha256' => str_repeat('a', 64),
        'total_size_bytes' => 128,
        'received_bytes' => 0,
        'chunk_size' => Config::uploadChunkSize(),
        'mime_type' => 'application/octet-stream',
        'metadata' => ['filename' => 'shape.bin'],
        'created_by' => 42,
    ])
        ->and($session->upload_id)->not->toBeEmpty()
        ->and($session->expires_at)->not->toBeNull();
});

it('initiate stores null metadata when the client sent an empty map', function (): void {
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'empty-meta.bin',
        totalSizeBytes: 4,
        metadata: [],
    ));

    expect($session->metadata)->toBeNull();
});

it('initiate leaves a tus session ready for PATCH after handler initialise', function (): void {
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: $storage,
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
    $session->forceFill(['status' => UploadStatus::Committed])->save();

    expect(fn () => app(UploadSessionManager::class)->markAssembled($session->refresh()))
        ->toThrow(ValidationException::class);
});

it('accepts initiate total_size_bytes exactly on max_bytes and rejects one byte over', function (): void {
    config()->set('vaulter.uploads.max_bytes', 10);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    $exact = $manager->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: 'exact.bin',
        totalSizeBytes: 10,
    ));

    expect($exact->total_size_bytes)->toBe(10);

    expect(fn () => $manager->initiate(new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: 'over.bin',
        totalSizeBytes: 11,
    )))->toThrow(ValidationException::class);
});

it('active-session cap allows max minus one and rejects at the ceiling', function (): void {
    config()->set('vaulter.uploads.max_active_sessions', 2);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);
    $data = fn (string $name) => new UploadInitData(
        storage: $storage,
        protocol: UploadProtocol::Tus,
        targetParent: $root,
        targetName: $name,
        totalSizeBytes: 4,
        actorId: 9001,
    );

    $manager->initiate($data('one.bin'));
    $manager->initiate($data('two.bin'));

    expect(fn () => $manager->initiate($data('three.bin')))
        ->toThrow(TooManyRequestsHttpException::class);
});

it('countActiveForActor counts only initiated and uploading rows', function (): void {
    $storage = UploadTestHelpers::storage();
    $read = app(UploadSessionReadRepository::class);

    UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    $uploading = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    $uploading->forceFill(['status' => UploadStatus::Uploading])->save();
    $aborted = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 55);
    $aborted->forceFill(['status' => UploadStatus::Aborted])->save();

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
    $session->forceFill(['mime_type' => 'image/png'])->save();

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($png), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CommitUploadAction::class)->execute($session->refresh());

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
        'storage_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'dest',
        'size_bytes' => 0,
    ]);
    $folder->save();

    $bytes = 'folder-target';
    $session = UploadTestHelpers::session(
        $storage,
        totalSizeBytes: strlen($bytes),
        parentId: $folder->getKey(),
        targetName: 'nested.bin',
    );

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($bytes), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CommitUploadAction::class)->execute($session->refresh());

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
        DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.$table.'_storage_id_foreign"');
    }

    UploadSession::query()->whereKey($session->getKey())->update(['storage_id' => $missingStorageId]);
    $session = $session->fresh();

    expect(fn () => app(CommitUploadAction::class)->execute($session))
        ->toThrow(RuntimeException::class, 'storage');
});

it('commit rejects image/jpg declared when magic bytes are PNG', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAD0lEQVR42mP8z5BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: strlen($png));
    $session->forceFill(['mime_type' => 'image/jpg'])->save();

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($png), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    expect(fn () => app(CommitUploadAction::class)->execute($session->refresh()))
        ->toThrow(UploadContentMismatchException::class);

    expect(Blob::query()->count())->toBe(0);
});

it('advanceReceived flips initiated to uploading on first chunk', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);

    app(UploadSessionStoreRepository::class)->advanceReceived($session, 3);

    expect($session->refresh()->status)->toBe(UploadStatus::Uploading)
        ->and($session->received_bytes)->toBe(3);
});
