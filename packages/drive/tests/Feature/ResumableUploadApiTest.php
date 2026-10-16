<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The resumable-upload REST surface (§2.8 / D16 / M3a-http): the tus CORE flow and
 * the S3-multipart flow through the API, plus the feature gate and the tus-disabled
 * gate. The slice runs open (the base suite's null role driver), so a bare
 * authenticated actor suffices — the authorization SEAM (AccessResolver) is wired in
 * the controllers and exercised by the DriveAuthorization tests.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function uploadStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('tus: POST create → Location + upload_id, HEAD offset, PATCH chunks → committed node', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $bytes = 'resumable http payload bytes'; // 28

    // Creation.
    $create = $this->postJson($base.'/uploads', [
        'protocol' => 'tus',
        'name' => 'http.txt',
        'total_size_bytes' => strlen($bytes),
        'mime_type' => 'text/plain',
    ])->assertCreated();

    $create->assertHeader('Tus-Resumable', '1.0.0');
    $uploadId = $create->json('data.upload_id');

    expect($uploadId)->not->toBeEmpty()
        ->and($create->headers->get('Location'))->toContain($uploadId);

    // HEAD: offset is 0.
    $this->call('HEAD', 'api/vaulter/v1/uploads/'.$uploadId, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '0');

    // First PATCH chunk.
    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        substr($bytes, 0, 10),
    )->assertNoContent()->assertHeader('Upload-Offset', '10');

    // HEAD now reflects the advanced offset (the resume point).
    $this->call('HEAD', 'api/vaulter/v1/uploads/'.$uploadId, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertHeader('Upload-Offset', '10');

    // Final PATCH completes → assemble → commit → 201 with the node.
    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '10', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        substr($bytes, 10),
    )
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file')
        ->assertJsonPath('data.name', 'http.txt')
        ->assertJsonPath('data.size_bytes', 28);

    expect(Node::query()->where('kind', 'file')->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('GET status returns the session shape', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $this->getJson('api/vaulter/v1/uploads/'.$uploadId)
        ->assertOk()
        ->assertJsonPath('data.upload_id', $uploadId)
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.received_bytes', 0)
        ->assertJsonPath('data.total_size_bytes', 100);
});

it('DELETE aborts the session', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $this->delete('api/vaulter/v1/uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])->assertNoContent();

    $this->getJson('api/vaulter/v1/uploads/'.$uploadId)
        ->assertJsonPath('data.status', 'aborted');
});

it('S3 multipart: POST parts → POST complete → committed node', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $part1 = str_repeat('X', 10);
    $part2 = str_repeat('Y', 6);

    $uploadId = $this->postJson($base.'/uploads', [
        'protocol' => 's3-multipart',
        'name' => 'multi.bin',
        'total_size_bytes' => 16,
    ])->json('data.upload_id');

    // Part 1.
    $this->call(
        'POST',
        'api/vaulter/v1/uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $part1,
    )->assertCreated()->assertJsonPath('part_no', 1);

    // Part 2.
    $this->call(
        'POST',
        'api/vaulter/v1/uploads/'.$uploadId.'/parts?part_no=2&byte_offset=10',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $part2,
    )->assertCreated();

    // Complete → assemble → commit.
    $this->postJson('api/vaulter/v1/uploads/'.$uploadId.'/complete')
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file')
        ->assertJsonPath('data.size_bytes', 16);

    expect(Blob::query()->count())->toBe(1);
});

it('404s an unknown upload_id', function (): void {
    $this->getJson('api/vaulter/v1/uploads/0193a5f0-9a3b-7c2d-8e4f-0000000000ff')->assertNotFound();
});

it('refuses the tus surface when tus is disabled', function (): void {
    config()->set('vaulter-drive.uploads.resumable.enabled', false);
    $storage = uploadStorage();

    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 10,
    ])->assertForbidden();
});

it('429s a resumable-upload session create past the vaulter-uploads rate limit (F-P1.8-01)', function (): void {
    config()->set('vaulter.routes.rate_limits.uploads', 1);
    $storage = uploadStorage();

    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'first.bin',
        'total_size_bytes' => 5,
    ])->assertCreated();

    // Same actor, same minute — the second create is over budget.
    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'second.bin',
        'total_size_bytes' => 5,
    ])->assertStatus(429);
});

it('429s a tus PATCH past the vaulter-uploads rate limit (F-P1.8-01)', function (): void {
    // The limiter bucket is keyed by actor, shared across EVERY vaulter-uploads
    // request (create + every PATCH) — the cap must cover the create call too, so
    // it is set BEFORE create: budget 2 (create + one PATCH), the second PATCH
    // is the 3rd hit and goes over.
    config()->set('vaulter.routes.rate_limits.uploads', 2);
    $storage = uploadStorage();

    $create = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'protocol' => 'tus',
        'name' => 'patch-throttle.bin',
        'total_size_bytes' => 4,
    ])->assertCreated();
    $uploadId = $create->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'ab',
    )->assertNoContent();

    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '2', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'cd',
    )->assertStatus(429);
});

it('refuses a new upload session past the active-session cap for the actor (F-P1.4-09)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 2);
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $this->postJson($base.'/uploads', ['name' => 'one.bin', 'total_size_bytes' => 5])->assertCreated();
    $this->postJson($base.'/uploads', ['name' => 'two.bin', 'total_size_bytes' => 5])->assertCreated();

    // A third OPEN session for the SAME actor is over the cap.
    $this->postJson($base.'/uploads', ['name' => 'three.bin', 'total_size_bytes' => 5])
        ->assertStatus(429);
});

it('does not cap a new session when max_active_sessions is 0 (unbounded, the default off-switch)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 0);
    $storage = uploadStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $this->postJson($base.'/uploads', ['name' => 'one.bin', 'total_size_bytes' => 5])->assertCreated();
    $this->postJson($base.'/uploads', ['name' => 'two.bin', 'total_size_bytes' => 5])->assertCreated();
    $this->postJson($base.'/uploads', ['name' => 'three.bin', 'total_size_bytes' => 5])->assertCreated();
});
