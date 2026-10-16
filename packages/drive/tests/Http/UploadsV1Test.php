<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Tests\Http\DriveV1;

/**
 * Resumable uploads over v1: tus create → HEAD → PATCH → commit and S3 parts → complete,
 * all under uploads/{upload}, with the protocol headers intact and errors as problem+json.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

/** @param array<string, string> $headers */
function tusPatch(TestCase $test, string $uploadId, int $offset, string $bytes, array $headers = []): TestResponse
{
    return $test->call('PATCH', V1::url('uploads/'.$uploadId), [], [], [], [
        'HTTP_UPLOAD_OFFSET' => (string) $offset,
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_ACCEPT' => 'application/json',
        ...$headers,
    ], $bytes);
}

it('runs a tus upload end to end under v1', function (): void {
    $created = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['protocol' => 'tus', 'name' => 'tus.bin', 'total_size_bytes' => 10])
        ->assertCreated()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Upload-Offset', '0')
        ->assertJsonPath('data.drive_id', $this->driveId)
        ->assertJsonPath('data.protocol', 'tus');
    $uploadId = $created->json('data.upload_id');
    expect($created->headers->get('Location'))->toEndWith('/api/vaulter/v1/uploads/'.$uploadId)
        ->and($created->json('data'))->not->toHaveKey('assembled_blob_id');

    $this->call('HEAD', V1::url('uploads/'.$uploadId), [], [], [], ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '0')
        ->assertHeader('Upload-Length', '10');

    tusPatch($this, $uploadId, 0, '12345')->assertNoContent()->assertHeader('Upload-Offset', '5');
    $this->getJson(V1::url('uploads/'.$uploadId))->assertOk()->assertJsonPath('data.received_bytes', 5);

    $done = tusPatch($this, $uploadId, 5, '67890')
        ->assertCreated()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Upload-Offset', '10')
        ->assertJsonPath('data.name', 'tus.bin')
        ->assertJsonPath('data.size_bytes', 10);
    expect($done->headers->get('ETag'))->toBe($done->json('data.etag'));
});

it('keeps the tus protocol refusals: a missing Tus-Resumable is 412 with the supported version', function (): void {
    $uploadId = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['name' => 'v.bin', 'total_size_bytes' => 4])->json('data.upload_id');

    $this->call('PATCH', V1::url('uploads/'.$uploadId), [], [], [], ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'abcd')
        ->assertStatus(412)
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('answers tus discovery and aborts a session', function (): void {
    $this->call('OPTIONS', V1::url('uploads/anything'))
        ->assertNoContent()
        ->assertHeader('Tus-Version', '1.0.0')
        ->assertHeader('Tus-Extension', 'creation,termination');

    $uploadId = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['name' => 'abort.bin', 'total_size_bytes' => 4])->json('data.upload_id');
    $this->call('DELETE', V1::url('uploads/'.$uploadId), [], [], [], ['HTTP_TUS_RESUMABLE' => '1.0.0'])->assertNoContent();
});

it('runs an S3 multipart upload end to end under v1', function (): void {
    $uploadId = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['protocol' => 's3-multipart', 'name' => 's3.bin', 'total_size_bytes' => 6])
        ->assertCreated()
        ->assertJsonPath('data.protocol', 's3-multipart')
        ->json('data.upload_id');

    $this->call('POST', V1::url('uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0'), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'abc')
        ->assertCreated()->assertJsonPath('part_no', 1);
    $this->call('POST', V1::url('uploads/'.$uploadId.'/parts?part_no=2&byte_offset=3'), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'def')
        ->assertCreated()->assertJsonPath('size', 3);

    $this->postJson(V1::url('uploads/'.$uploadId.'/complete'))
        ->assertCreated()
        ->assertJsonPath('data.name', 's3.bin')
        ->assertJsonPath('data.size_bytes', 6);
});

it('requires If-Match for a session that targets an existing file and accepts the current tag', function (): void {
    $file = V1::file($this->drive, 'target.bin', 'old');

    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['node_id' => $file->id, 'total_size_bytes' => 3])
        ->assertStatus(428)->assertJsonPath('code', 'precondition_required');

    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['node_id' => $file->id, 'total_size_bytes' => 3], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertCreated()
        ->assertJsonPath('data.node_id', $file->id);
});

it('keeps a session to its owner and answers an unknown upload id as not found', function (): void {
    $uploadId = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['name' => 'mine.bin', 'total_size_bytes' => 4])->json('data.upload_id');

    $this->actingAs(V1::user(2));
    $this->getJson(V1::url('uploads/'.$uploadId))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->getJson(V1::url('uploads/no-such-upload'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('refuses resumable uploads with 403 profile_restriction when the feature is off, routes still registered', function (): void {
    config()->set('vaulter-drive.uploads.resumable.enabled', false);

    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['name' => 'off.bin', 'total_size_bytes' => 4])
        ->assertForbidden()
        ->assertJsonPath('code', 'profile_restriction');
    $this->call('OPTIONS', V1::url('uploads/anything'), [], [], [], ['HTTP_ACCEPT' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('code', 'profile_restriction');

    // Without View the caller learns nothing, the feature flag included.
    $this->actingAs(V1::user(2));
    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), ['name' => 'off.bin', 'total_size_bytes' => 4])->assertNotFound();
    expect(DriveTestHelpers::model($this->drive->root())->exists)->toBeTrue();
});
