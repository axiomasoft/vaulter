<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(function (): void {
    Storage::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

it('returns all resume headers from creation through commit', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    $create = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'headers.bin',
        'total_size_bytes' => 4,
    ])->assertCreated()->assertHeader('Upload-Offset', '0');
    $uploadId = $create->json('data.upload_id');
    $uri = 'api/vaulter/v1/uploads/'.$uploadId;

    $this->call('HEAD', $uri, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Upload-Length', '4')
        ->assertHeader('Cache-Control', 'no-store, private');

    foreach ([['ab', 0, 2, 204], ['cd', 2, 4, 201]] as [$body, $offset, $next, $status]) {
        $this->call('PATCH', $uri, server: [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
        ], content: $body)
            ->assertStatus($status)
            ->assertHeader('Tus-Resumable', '1.0.0')
            ->assertHeader('Upload-Offset', (string) $next);
    }
});

it('omits Upload-Length when the upload size is deferred', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'deferred.bin',
    ])->assertCreated()->json('data.upload_id');

    $this->call('HEAD', 'api/vaulter/v1/uploads/'.$uploadId, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertNoContent()->assertHeaderMissing('Upload-Length');
});

it('omits Tus-Max-Size for unlimited uploads and advertises a one-byte ceiling', function (int $max): void {
    config()->set('vaulter-drive.uploads.max_file_size', $max);
    $response = $this->call('OPTIONS', 'api/vaulter/v1/uploads/discovery')->assertNoContent();

    if ($max === 0) {
        $response->assertHeaderMissing('Tus-Max-Size');
    } else {
        $response->assertHeader('Tus-Max-Size', '1');
    }
})->with(['unlimited' => [0], 'one byte' => [1]]);

it('accepts Content-Length exactly at the chunk ceiling', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'boundary.bin',
        'total_size_bytes' => 8,
    ])->assertCreated()->json('data.upload_id');

    $this->call('PATCH', 'api/vaulter/v1/uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '4',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'abcd')->assertNoContent()->assertHeader('Upload-Offset', '4');
});

it('continues metadata whitelisting after an unknown key and lets headers override JSON', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'metadata.bin',
        'metadata' => ['filename' => 'json.bin'],
    ], ['Upload-Metadata' => 'unknown '.base64_encode('ignored').',filename '.base64_encode('header.bin')])
        ->assertCreated()->json('data.upload_id');

    expect(UploadTestHelpers::clientMetadata(UploadSession::query()->where('upload_id', $uploadId)->sole()))
        ->toBe(['filename' => 'header.bin']);
});
