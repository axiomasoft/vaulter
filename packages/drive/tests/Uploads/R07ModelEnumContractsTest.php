<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

it('R07Model casts upload part integers and received_at after persistence', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage);
    $part = app(UploadPartStoreRepository::class)->record(
        session: $session,
        partNo: 3,
        byteOffset: 128,
        sizeBytes: 64,
        etag: '"etag"',
    );

    $fresh = UploadPart::query()
        ->where('upload_session_id', $session->getKey())
        ->where('part_no', 3)
        ->firstOrFail();

    expect($fresh->part_no)->toBe(3)
        ->and($fresh->byte_offset)->toBe(128)
        ->and($fresh->size_bytes)->toBe(64)
        ->and($fresh->received_at)->not->toBeNull()
        ->and(is_int($fresh->part_no))->toBeTrue()
        ->and(is_int($fresh->byte_offset))->toBeTrue()
        ->and(is_int($fresh->size_bytes))->toBeTrue();
    expect($part->part_no)->toBe($fresh->part_no);
});

it('R07Model casts upload session protocol status and total_size_bytes strictly', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session(
        $storage,
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 4096,
    );

    $fresh = UploadSession::query()->findOrFail($session->getKey());

    expect($fresh->protocol)->toBe(UploadProtocol::S3Multipart)
        ->and($fresh->status)->toBe(UploadStatus::Initiated)
        ->and($fresh->total_size_bytes)->toBe(4096)
        ->and(is_int($fresh->total_size_bytes))->toBeTrue();
});

it('R07Model lists exact UploadStatus allowedTransitions including order', function (): void {
    expect(UploadStatus::Initiated->allowedTransitions())->toBe([
        UploadStatus::Uploading,
        UploadStatus::Assembled,
        UploadStatus::Aborted,
        UploadStatus::Expired,
    ])->and(UploadStatus::Initiated->allowedTransitions())->not->toContain(UploadStatus::Committed);

    expect(UploadStatus::Uploading->allowedTransitions())->toBe([
        UploadStatus::Assembled,
        UploadStatus::Aborted,
        UploadStatus::Expired,
    ]);

    expect(UploadStatus::Assembled->allowedTransitions())->toBe([
        UploadStatus::Committed,
        UploadStatus::Aborted,
        UploadStatus::Expired,
    ]);
});

it('R07Model resolves UploadFileData name from override not original', function (): void {
    $storage = UploadTestHelpers::storage();
    $stream = DriveTestHelpers::streamFor('bytes');

    $withOverride = new IngestFileInput(
        storage: $storage,
        stream: $stream,
        originalName: 'original.bin',
        name: 'override.bin',
    );

    $withoutOverride = new IngestFileInput(
        storage: $storage,
        stream: $stream,
        originalName: 'original.bin',
        name: null,
    );

    expect($withOverride->resolvedName())->toBe('override.bin')
        ->and($withoutOverride->resolvedName())->toBe('original.bin');
});

it('R07Model returns the full UploadSessionResource JSON shape with nullables and ISO expires_at', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'resource-shape.bin',
        'total_size_bytes' => 16,
        'mime_type' => 'application/octet-stream',
    ])->assertCreated()->json('data.upload_id');

    $session = UploadSession::query()->where('upload_id', $uploadId)->sole();

    $data = $this->getJson('api/vaulter/resumable-uploads/'.$uploadId)
        ->assertOk()
        ->json('data');

    expect($data)->toEqual([
        'upload_id' => $uploadId,
        'protocol' => 'tus',
        'status' => 'initiated',
        'received_bytes' => 0,
        'total_size_bytes' => 16,
        'chunk_size' => $session->chunk_size,
        'mime_type' => 'application/octet-stream',
        'node_id' => null,
        'target_parent_id' => $session->target_parent_id,
        'target_name' => 'resource-shape.bin',
        'assembled_blob_id' => null,
        'expires_at' => $session->expires_at?->toIso8601String(),
    ])
        ->and($data['expires_at'])->toBeString()
        ->and($data['expires_at'])->not->toBeEmpty();
});

it('R07Model returns all-false storage abilities without an actor or root node', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();
    $storage->forceFill(['root_node_id' => null])->save();

    $guestRequest = Request::create('/api/vaulter/storages/'.$storage->getKey());
    $guestRequest->setUserResolver(static fn (): null => null);

    expect((new DriveResource($storage->refresh()))->toArray($guestRequest)['abilities'])->toEqual([
        'view' => false,
        'create_folder' => false,
        'upload' => false,
        'manage' => false,
    ]);

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertNotFound();
});

it('R07Model requires both root create ability and workspace gate for storage create flags', function (): void {
    config()->set('vaulter.drivers.permission', 'gate');
    $storage = Vaulter::for(DriveTestHelpers::host())->model();
    $root = $storage->rootNode()->firstOrFail();
    $actor = (new TestUser)->forceFill(['id' => 17]);

    Gate::define('vaulter.create', static fn (): bool => false);
    AccessTestHelpers::grant($root, GrantLevel::Writer, scope: GrantScope::Subtree, subjectId: 17);

    $request = Request::create('/api/vaulter/storages/'.$storage->getKey());
    $request->setUserResolver(static fn () => $actor);

    expect((new DriveResource($storage))->toArray($request)['abilities'])->toEqual([
        'view' => true,
        'create_folder' => false,
        'upload' => false,
        'manage' => false,
    ]);

    Gate::define('vaulter.create', static fn (): bool => true);
    $storageReaderOnly = Vaulter::for(DriveTestHelpers::host())->model();
    AccessTestHelpers::grant($storageReaderOnly->rootNode()->firstOrFail(), GrantLevel::Reader, scope: GrantScope::Item, subjectId: 17);

    expect((new DriveResource($storageReaderOnly))->toArray($request)['abilities'])->toEqual([
        'view' => true,
        'create_folder' => false,
        'upload' => false,
        'manage' => false,
    ]);

    $storageBoth = Vaulter::for(DriveTestHelpers::host())->model();
    AccessTestHelpers::grant($storageBoth->rootNode()->firstOrFail(), GrantLevel::Writer, scope: GrantScope::Subtree, subjectId: 17);

    expect((new DriveResource($storageBoth))->toArray($request)['abilities'])->toEqual([
        'view' => true,
        'create_folder' => true,
        'upload' => true,
        'manage' => false,
    ]);
});

it('R07Model treats a non-Authorizable request user as having no actor for storage abilities', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $request = Request::create('/api/vaulter/storages/'.$storage->getKey());
    $request->setUserResolver(static fn (): object => new stdClass);

    expect((new DriveResource($storage))->toArray($request)['abilities'])->toEqual([
        'view' => false,
        'create_folder' => false,
        'upload' => false,
        'manage' => false,
    ]);
});

it('R07Model exposes real storage ability flags when actor and root are present', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.abilities', [
            'view' => true,
            'create_folder' => true,
            'upload' => true,
            'manage' => true,
        ]);
});
