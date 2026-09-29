<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\TusUploadHandler;

beforeEach(function (): void {
    Storage::fake('local');
});

it('accepts bytes when max_bytes is zero and treats zero as unlimited', function (): void {
    config()->set('vaulter.uploads.max_bytes', 0);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);

    $result = app(TusUploadHandler::class)->patch($session, UploadTestHelpers::streamFor('abcd'), 0);

    expect($result)->toBe(['offset' => 4, 'complete' => true])
        ->and($session->refresh()->received_bytes)->toBe(4);
});

it('accepts a chunk exactly on the configured max_bytes boundary', function (): void {
    config()->set('vaulter.uploads.max_bytes', 4);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);

    $result = app(TusUploadHandler::class)->patch($session, UploadTestHelpers::streamFor('abcd'), 0);

    expect($result)->toBe(['offset' => 4, 'complete' => true])
        ->and($session->refresh()->received_bytes)->toBe(4);
});

it('rejects a chunk that exceeds max_bytes before recording its offset', function (): void {
    config()->set('vaulter.uploads.max_bytes', 3);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);

    try {
        app(TusUploadHandler::class)->patch($session, UploadTestHelpers::streamFor('abcd'), 0);
        $this->fail('Expected the byte ceiling to reject the chunk.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('received_bytes');
    }

    expect($session->refresh()->received_bytes)->toBe(0);
});
