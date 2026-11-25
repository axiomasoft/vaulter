<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Per-chunk hard cap ON THE PATH where the damage happens.
 *
 * The StagedUpload::assertChunkWithinLimit() docblock promises a refusal "BEFORE any bytes are staged",
 * but the barrier stood BEHIND the point of damage: the controller first ran
 * `fwrite($stream, $request->getContent())`, materializing the whole PATCH body into a PHP string,
 * and only then did append() ask about the limit. On top of that the key was off by default
 * (max_chunk_bytes = 0) with max_bytes = 5 GiB: one authorized PATCH could exhaust a worker's memory.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function chunkLimitStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('max_chunk_bytes has a non-empty default, so chunks are bounded out of the box', function (): void {
    // Config honesty: before the fix the default was 0 ("cap disabled"), so the promised
    // protection was not there out of the box at all.
    expect(app(DriveOptions::class)->uploads->resumable->maxChunkSize)->toBeGreaterThan(0)
        ->and(config('vaulter-drive.uploads.resumable.max_chunk_size'))->toBeGreaterThan(0);
});

it('a tus PATCH with Content-Length above max_chunk_bytes is rejected BEFORE the body is read', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'big.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $body = str_repeat('x', 64); // 64 > 8

    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [], [], [],
        [
            'HTTP_UPLOAD_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_LENGTH' => (string) strlen($body),
        ],
        $body,
    )->assertStatus(413);

    // Not a single byte reached the staging area.
    expect(StorageFacade::disk('local')->exists("tmp/uploads/{$uploadId}"))->toBeFalse();
});

it('an S3 part with Content-Length above max_chunk_bytes is rejected BEFORE the body is read', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'protocol' => 's3-multipart',
        'name' => 'big.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $body = str_repeat('x', 64);

    $this->call(
        'POST',
        'api/vaulter/v1/uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [],
        ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($body)],
        $body,
    )->assertStatus(413);

    expect(StorageFacade::disk('local')->exists("tmp/uploads/{$uploadId}.parts/1"))->toBeFalse();
});

it('a chunk without a declared length (an unstatable stream such as php://input) is still bounded', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // Emulates php://input: a socket pair cannot be stat-ed (fstat reports size 0), exactly like
    // a real request body. A check "by fstat" does NOT fire on such a stream, so the barrier must
    // sit on the copy itself.
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($writer, str_repeat('x', 64));
    fclose($writer);

    expect(fstat($reader)['size'])->toBe(0);

    expect(fn () => app(StagedUpload::class)->append($session, $reader, 0))
        ->toThrow(ValidationException::class);

    fclose($reader);

    expect(app(StagedUpload::class)->size($session->fresh()))->toBe(0);
});
