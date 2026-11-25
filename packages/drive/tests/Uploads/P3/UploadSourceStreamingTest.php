<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Stream;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;

it('consumes a borrowed PSR stream from its current position and never closes it', function (): void {
    $resource = UploadTestHelpers::streamFor('prefixpayload');
    $psr = new Stream($resource);
    $psr->read(6);
    $prepared = app(StagedUpload::class)->stageSource(UploadSource::fromPsrStream($psr, 'x.txt'), 7);
    try {
        expect($prepared->facts->size)->toBe(7)->and($prepared->facts->sha256)->toBe(hash('sha256', 'payload'));
    } finally {
        $prepared->discard();
    }
    expect($psr->isReadable())->toBeTrue()->and(is_resource($resource))->toBeTrue();
    $psr->close();
});

it('rejects actual oversize PSR bytes without closing the borrowed stream', function (): void {
    $psr = new Stream(UploadTestHelpers::streamFor('payload'));
    expect(fn () => app(StagedUpload::class)->stageSource(UploadSource::fromPsrStream($psr, 'x.txt'), 3))->toThrow(FileSizeLimitExceededException::class);
    expect($psr->isReadable())->toBeTrue();
    $psr->close();
});

it('closes owned source handles and discards its prepared temporary object', function (): void {
    $source = UploadSource::fromString('content', 'file.txt');
    $prepared = app(StagedUpload::class)->stageSource($source);
    $path = $prepared->staged->path();
    expect(is_file($path))->toBeTrue();
    $prepared->discard();
    expect(is_file($path))->toBeFalse();
});
