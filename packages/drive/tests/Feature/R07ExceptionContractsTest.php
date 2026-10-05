<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

function r07ErrorDocs(string $anchor): string
{
    return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guide/errors.md#'.$anchor;
}

it('R07Exception keeps UploadConflictException forSession message and 409 render body', function (): void {
    $uploadId = '0193a5f0-9a3b-7c2d-8e4f-0000000000aa';
    $exception = UploadConflictException::forSession($uploadId);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Upload session [{$uploadId}] is being modified by a concurrent request. ".
        'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
        'Docs: '.r07ErrorDocs('upload-conflict'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'upload_conflict']);
});

it('R07Exception keeps UploadConflictException forClosedSession message and 409 render body', function (): void {
    $uploadId = '0193a5f0-9a3b-7c2d-8e4f-0000000000bb';
    $exception = UploadConflictException::forClosedSession($uploadId);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Upload session [{$uploadId}] is no longer open — it was expired or aborted while this chunk was in flight. ".
        'Fix: re-read the session HEAD; a terminal session is never re-opened, so start a new upload. '.
        'Docs: '.r07ErrorDocs('upload-conflict'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'upload_conflict']);
});

it('R07Exception keeps CycleException forMove message', function (): void {
    $nodeId = 42;
    $intoId = 99;
    $exception = NodeCycleException::forMove($nodeId, $intoId);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Cannot move node [{$nodeId}] into [{$intoId}] — the destination is the node itself or one of ".
        'its descendants, which would create a cycle. '.
        'Fix: choose a destination outside the moved node\'s own subtree. '.
        'Docs: '.r07ErrorDocs('move-cycle'),
    );
});

it('R07Exception keeps QuotaExceededException forStorage message and 413 render body', function (): void {
    $exception = QuotaExceededException::forStorage(
        storageId: 7,
        quotaBytes: 100,
        usedBytes: 90,
        incomingBytes: 20,
    );

    expect($exception->getMessage())->toBe(
        '[Vaulter] Upload of 20 bytes exceeds the quota for storage [7] (used 90 of 100). '.
        'Fix: raise v_drives.quota_bytes, free space, or set quota_bytes to null for unlimited. '.
        'Docs: '.r07ErrorDocs('quota-exceeded'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(413)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'quota_exceeded']);
});

it('R07Exception keeps StagedUploadMissingException forSession message and 409 render body', function (): void {
    $uploadId = '0193a5f0-9a3b-7c2d-8e4f-0000000000cc';
    $offset = 512;
    $exception = StagedUploadMissingException::forSession($uploadId, $offset);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Upload session [{$uploadId}] claims offset {$offset} but its staged object is gone — ".
        'the already-received bytes cannot be recovered, so the chunk is refused rather than silently truncating the file. '.
        'Fix: re-read the session HEAD; if the session is no longer open, start a new upload. '.
        'Docs: '.r07ErrorDocs('staged-upload-missing'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'upload_conflict']);
});

it('R07Exception keeps UploadContentMismatchException forSession message and 422 render body', function (): void {
    $uploadId = '0193a5f0-9a3b-7c2d-8e4f-0000000000dd';
    $declaredMime = 'image/png';
    $exception = UploadContentMismatchException::forSession($uploadId, $declaredMime);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Upload session [{$uploadId}] declared mime [{$declaredMime}] but the ".
        'assembled object contents do not match it. '.
        'Fix: upload a file whose contents match the declared Content-Type. '.
        'Docs: '.r07ErrorDocs('upload-content-mismatch'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'upload_rejected', 'reason' => 'mime_mismatch']);
});

it('R07Exception keeps CrossStorageMoveException between message', function (): void {
    $from = 1;
    $to = 2;
    $exception = CrossDriveOperationException::between($from, $to);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Cannot move a node from storage [{$from}] into storage [{$to}]. ".
        'Fix: a move stays within one storage tree — copy across storages or re-home explicitly instead. '.
        'Docs: '.r07ErrorDocs('cross-storage-move'),
    );
});

it('R07Exception keeps OffsetMismatchException message render headers and JSON at a non-zero server offset', function (): void {
    $serverOffset = 5;
    $exception = new OffsetMismatchException($serverOffset);

    expect($exception->getMessage())->toBe(
        "[Vaulter] Upload-Offset does not match the server offset {$serverOffset}. ".
        'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
        'Docs: '.r07ErrorDocs('upload-conflict'),
    );

    $response = $exception->render(Request::create('/api/vaulter/__exception'));
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toEqual(['message' => $exception->getMessage(), 'code' => 'upload_offset_mismatch'])
        ->and($response->headers->get('Upload-Offset'))->toBe('5')
        ->and($response->headers->get('Tus-Resumable'))->toBe('1.0.0');
});

it('R07Exception surfaces offset mismatch over HTTP with the full message and tus headers', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'r07-offset.bin',
        'total_size_bytes' => 20,
    ])->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        '12345',
    )->assertNoContent();

    $response = $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0'],
        'xxxxx',
    );

    $expectedMessage = '[Vaulter] Upload-Offset does not match the server offset 5. '.
        'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
        'Docs: '.r07ErrorDocs('upload-conflict');

    $response->assertStatus(409)
        ->assertHeader('Upload-Offset', '5')
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertJson(['message' => $expectedMessage]);
});
