<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * F-P1.4-12: `authorizeSession` checks the ability on the target folder/node but,
 * before this fix, never checked WHO created the session — any actor with Create in
 * the same folder (or Edit on the same node), having merely learned the (unguessable)
 * upload_id, could HEAD/PATCH/DELETE someone else's in-flight session. The slice runs
 * the open base suite (null role driver — every ability check passes for any
 * authenticated actor), which is exactly what makes the ability-only gate insufficient
 * and isolates the ownership check as the thing actually under test.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
});

function ownershipStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

function actingAsActor(int $id): void
{
    test()->actingAs((new TestUser)->forceFill(['id' => $id]));
}

it('владелец сессии продвигает её PATCH-чанком (F-P1.4-12, контроль)', function (): void {
    actingAsActor(1);
    $storage = ownershipStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'owner.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'abcd',
    )->assertCreated();
});

it('чужой актор с правом Create в той же папке НЕ может PATCH чужую сессию (F-P1.4-12, red-before: проходит)', function (): void {
    actingAsActor(1);
    $storage = ownershipStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'victim.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    // Same storage, same open base-suite ability grant — different actor.
    actingAsActor(2);

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        'abcd',
    )->assertForbidden();
});

it('чужой актор НЕ может DELETE (abort) чужую сессию (F-P1.4-12)', function (): void {
    actingAsActor(1);
    $storage = ownershipStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'victim2.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    actingAsActor(2);

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId)->assertForbidden();

    // The session survives — the abort never happened.
    actingAsActor(1);
    $this->getJson('api/vaulter/resumable-uploads/'.$uploadId)
        ->assertJsonPath('data.status', 'initiated');
});

it('чужой актор НЕ может HEAD чужую сессию (F-P1.4-12)', function (): void {
    actingAsActor(1);
    $storage = ownershipStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'victim3.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    actingAsActor(2);

    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId)->assertForbidden();
});

it('S3-multipart: чужой актор НЕ может POST parts на чужую сессию (F-P1.4-12)', function (): void {
    actingAsActor(1);
    $storage = ownershipStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 's3-multipart',
        'name' => 'victim-multi.bin',
        'total_size_bytes' => 10,
    ])->json('data.upload_id');

    actingAsActor(2);

    $this->call(
        'POST',
        'api/vaulter/resumable-uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], str_repeat('X', 10),
    )->assertForbidden();
});
