<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The resumable-upload REST surface (§2.8 / D16 / M3a-http): the tus CORE flow and
 * the S3-multipart flow through the API, plus the feature gate and the tus-disabled
 * gate. The slice runs open (the base suite's null role driver), so a bare
 * authenticated actor suffices — the authorization SEAM (AccessResolver) is wired in
 * the controllers and exercised by the DriveAuthorization tests.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function uploadStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

it('tus: POST create → Location + upload_id, HEAD offset, PATCH chunks → committed node', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $bytes = 'resumable http payload bytes'; // 28

    // Creation.
    $create = $this->postJson($base.'/resumable-uploads', [
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
    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId)
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '0');

    // First PATCH chunk.
    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        substr($bytes, 0, 10),
    )->assertNoContent()->assertHeader('Upload-Offset', '10');

    // HEAD now reflects the advanced offset (the resume point).
    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId)
        ->assertHeader('Upload-Offset', '10');

    // Final PATCH completes → assemble → commit → 201 with the node.
    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [],
        [],
        [],
        ['HTTP_UPLOAD_OFFSET' => '10', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
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
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $this->getJson('api/vaulter/resumable-uploads/'.$uploadId)
        ->assertOk()
        ->assertJsonPath('data.upload_id', $uploadId)
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.received_bytes', 0)
        ->assertJsonPath('data.total_size_bytes', 100);
});

it('DELETE aborts the session', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId)->assertNoContent();

    $this->getJson('api/vaulter/resumable-uploads/'.$uploadId)
        ->assertJsonPath('data.status', 'aborted');
});

it('S3 multipart: POST parts → POST complete → committed node', function (): void {
    $storage = uploadStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $part1 = str_repeat('X', 10);
    $part2 = str_repeat('Y', 6);

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 's3-multipart',
        'name' => 'multi.bin',
        'total_size_bytes' => 16,
    ])->json('data.upload_id');

    // Part 1.
    $this->call(
        'POST',
        'api/vaulter/resumable-uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $part1,
    )->assertCreated()->assertJsonPath('part_no', 1);

    // Part 2.
    $this->call(
        'POST',
        'api/vaulter/resumable-uploads/'.$uploadId.'/parts?part_no=2&byte_offset=10',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $part2,
    )->assertCreated();

    // Complete → assemble → commit.
    $this->postJson('api/vaulter/resumable-uploads/'.$uploadId.'/complete')
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file')
        ->assertJsonPath('data.size_bytes', 16);

    expect(Blob::query()->count())->toBe(1);
});

it('404s an unknown upload_id', function (): void {
    $this->getJson('api/vaulter/resumable-uploads/0193a5f0-9a3b-7c2d-8e4f-0000000000ff')->assertNotFound();
});

it('refuses the tus surface when tus is disabled', function (): void {
    config()->set('vaulter.uploads.tus.enabled', false);
    $storage = uploadStorage();

    $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'x.bin',
        'total_size_bytes' => 10,
    ])->assertForbidden();
});
