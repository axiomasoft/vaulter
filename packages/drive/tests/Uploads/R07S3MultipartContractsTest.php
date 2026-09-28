<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Data\Uploads\UploadInitData;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

function r07InitiateS3(int $totalBytes, string $name = 'contract.bin'): UploadSession
{
    return app(UploadSessionManager::class)->initiate(new UploadInitData(
        storage: UploadTestHelpers::storage(),
        protocol: UploadProtocol::S3Multipart,
        targetParent: UploadTestHelpers::storage()->rootNode()->first(),
        targetName: $name,
        totalSizeBytes: $totalBytes,
        mimeType: 'application/octet-stream',
    ));
}

it('initialise returns only the session chunk_size hint', function (): void {
    $session = r07InitiateS3(totalBytes: 64);

    expect(app(S3MultipartHandler::class)->initialise($session->refresh()))->toEqual([
        'chunk_size' => $session->chunk_size,
    ]);
});

it('putPart records exact byte_offset, size, etag md5 wrapper and part_sha256', function (): void {
    $bytes = 'part-alpha-bytes';
    $session = r07InitiateS3(totalBytes: strlen($bytes));
    $handler = app(S3MultipartHandler::class);

    $result = $handler->putPart(
        $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor($bytes),
    );

    $expectedEtag = '"'.md5($bytes).'"';

    expect($result)->toEqual([
        'part_no' => 1,
        'size' => strlen($bytes),
        'etag' => $expectedEtag,
    ]);

    $row = UploadPart::query()->where('upload_session_id', $session->getKey())->sole();

    expect($row->part_no)->toBe(1)
        ->and($row->byte_offset)->toBe(0)
        ->and($row->size_bytes)->toBe(strlen($bytes))
        ->and($row->etag)->toBe($expectedEtag)
        ->and($row->part_sha256)->toBe(hash('sha256', $bytes))
        ->and($session->refresh()->received_bytes)->toBe(strlen($bytes));
});

it('sums recorded part sizes into received_bytes across multiple parts', function (): void {
    $part1 = 'AAA';
    $part2 = 'BBBB';
    $session = r07InitiateS3(totalBytes: strlen($part1.$part2));
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($part1));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: 3, chunk: UploadTestHelpers::streamFor($part2));

    expect($session->refresh()->received_bytes)->toBe(7)
        ->and(UploadPart::query()->where('upload_session_id', $session->getKey())->orderBy('part_no')->get()->map(fn (UploadPart $p): array => [
            'part_no' => $p->part_no,
            'byte_offset' => $p->byte_offset,
            'size_bytes' => $p->size_bytes,
        ])->all())->toEqual([
            ['part_no' => 1, 'byte_offset' => 0, 'size_bytes' => 3],
            ['part_no' => 2, 'byte_offset' => 3, 'size_bytes' => 4],
        ]);
});

it('complete returns the stitched byte length and advances received_bytes to match', function (): void {
    $whole = 'left'.'right';
    $session = r07InitiateS3(totalBytes: strlen($whole));
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('left'));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: 4, chunk: UploadTestHelpers::streamFor('right'));

    $assembled = $handler->complete($session->refresh());

    expect($assembled)->toBe(9)
        ->and($session->refresh()->received_bytes)->toBe(9)
        ->and(app(S3MultipartHandler::class)->isComplete($session->refresh()))->toBeTrue();
});

it('isComplete is false while parts exist but the staged assembly is missing', function (): void {
    $session = r07InitiateS3(totalBytes: 5);
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('12345'));

    expect($session->refresh()->received_bytes)->toBe(5)
        ->and($handler->isComplete($session->refresh()))->toBeFalse();
});

it('rejects complete when a staged part object is missing', function (): void {
    $session = r07InitiateS3(totalBytes: 4);
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('abcd'));

    Storage::disk('local')->delete("tmp/uploads/{$session->upload_id}.parts/1");

    expect(fn () => $handler->complete($session->refresh()))
        ->toThrow(ValidationException::class, 'Upload part 1 is missing');
});

it('rejects complete when copied bytes do not match the recorded part size', function (): void {
    $session = r07InitiateS3(totalBytes: 4);
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('abcd'));

    UploadPart::query()->where('upload_session_id', $session->getKey())->update(['size_bytes' => 3]);

    expect(fn () => $handler->complete($session->refresh()))
        ->toThrow(ValidationException::class, 'size does not match its recorded bytes');
});

it('rejects a part body one byte over max_chunk_bytes at the handler barrier', function (): void {
    config()->set('vaulter.uploads.max_chunk_bytes', 4);
    $session = r07InitiateS3(totalBytes: 100);
    $handler = app(S3MultipartHandler::class);

    expect(fn () => $handler->putPart(
        $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('12345'),
    ))->toThrow(ValidationException::class, 'maximum chunk size of 4 bytes');

    expect(UploadPart::query()->count())->toBe(0);
});

it('HTTP parts echo the handler etag map and default part_no from X-Part-Number', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();
    $body = 'api-part';

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'protocol' => 's3-multipart',
        'name' => 'api-contract.bin',
        'total_size_bytes' => strlen($body),
    ])->json('data.upload_id');

    $response = $this->call(
        'POST',
        'api/vaulter/resumable-uploads/'.$uploadId.'/parts?byte_offset=0',
        [], [], [],
        ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PART_NUMBER' => '1'],
        $body,
    )->assertCreated();

    expect($response->json())->toEqual([
        'part_no' => 1,
        'size' => strlen($body),
        'etag' => '"'.md5($body).'"',
    ]);
});

it('HTTP parts 404 an unknown upload_id', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $this->call(
        'POST',
        'api/vaulter/resumable-uploads/0193a5f0-9a3b-7c2d-8e4f-000000000099/parts?part_no=1&byte_offset=0',
        [], [], [],
        ['CONTENT_TYPE' => 'application/octet-stream'],
        'x',
    )->assertNotFound();
});
