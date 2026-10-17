<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

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
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'resource-shape.bin',
        'total_size_bytes' => 16,
        'mime_type' => 'application/octet-stream',
    ])->assertCreated()->json('data.upload_id');

    $session = UploadSession::query()->where('upload_id', $uploadId)->sole();

    $data = $this->getJson('api/vaulter/v1/uploads/'.$uploadId)
        ->assertOk()
        ->json('data');

    expect($data)->toEqual([
        'upload_id' => $uploadId,
        'drive_id' => (string) $storage->getKey(),
        'protocol' => 'tus',
        'status' => 'initiated',
        'received_bytes' => 0,
        'total_size_bytes' => 16,
        'chunk_size' => $session->chunk_size,
        'mime_type' => 'application/octet-stream',
        'node_id' => null,
        'target_parent_id' => (string) $session->target_parent_id,
        'target_name' => 'resource-shape.bin',
        'expires_at' => SnapshotValue::iso($session->expires_at),
    ])
        ->and($data['expires_at'])->toBeString()
        ->and($data['expires_at'])->not->toBeEmpty();
});

it('R07Model hides a drive without a root node from everyone', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    app(VaulterDatabase::class)->write(fn () => $storage->forceFill(['root_node_id' => null])->save());

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertNotFound()
        ->assertJsonPath('code', 'drive_not_found');
});

it('R07Model leaves what the caller may do on a drive to its root node abilities', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $drive = $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())->assertOk()->assertJsonMissingPath('data.abilities');
    $this->getJson('api/vaulter/v1/nodes/'.$drive->json('data.root_node_id'))
        ->assertOk()
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.create', true)
        ->assertJsonPath('data.abilities.manage', true);
});
