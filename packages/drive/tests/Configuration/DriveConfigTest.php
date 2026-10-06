<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

it('reads drive settings live and uses the canonical byte ceiling', function (): void {
    $repository = new Repository(['vaulter-drive' => require __DIR__.'/../../config/vaulter-drive.php']);
    $config = new DriveConfig($repository);

    expect($config->maxFileSize())->toBe(5_368_709_120)
        ->and($config->resumableEnabled())->toBeTrue()
        ->and($config->model('upload_session'))->toBe(UploadSession::class)
        ->and($config->model('upload_part'))->toBe(UploadPart::class);

    $repository->set('vaulter-drive.uploads.max_file_size', 1025);
    $repository->set('vaulter-drive.uploads.resumable.enabled', false);
    $repository->set('vaulter-drive.names.max_length', 100);

    expect($config->maxFileSize())->toBe(1025)
        ->and($config->resumableEnabled())->toBeFalse()
        ->and($config->nameMaxLength())->toBe(100);
});

it('preserves resumable boolean casting for live canonical settings', function (mixed $value, bool $enabled): void {
    $repository = new Repository;
    $config = new DriveConfig($repository);

    expect($config->resumableEnabled())->toBeTrue();

    $repository->set('vaulter-drive.uploads.resumable.enabled', $value);

    expect($config->resumableEnabled())->toBe($enabled);
})->with([
    'integer zero' => [0, false],
    'string zero' => ['0', false],
    'empty string' => ['', false],
    'false' => [false, false],
    'true' => [true, true],
    'true string' => ['true', true],
    'string one' => ['1', true],
    'nonempty false string' => ['false', true],
]);

it('preserves bounded resumable defaults and filters metadata keys', function (): void {
    $config = new DriveConfig(new Repository(['vaulter-drive' => ['uploads' => ['resumable' => [
        'chunk_size' => 0, 'max_chunk_size' => -2, 'session_ttl_hours' => 0,
        'max_active_sessions_per_actor' => -1,
        'metadata' => ['allowed_keys' => ['filename', '', false], 'max_value_length' => 0],
    ]]]]));

    expect($config->chunkSize())->toBe(1)
        ->and($config->maxChunkSize())->toBe(0)
        ->and($config->sessionTtlHours())->toBe(1)
        ->and($config->maxActiveSessionsPerActor())->toBe(0)
        ->and($config->metadataAllowedKeys())->toBe(['filename'])
        ->and($config->metadataMaxValueLength())->toBe(1);
});

it('rejects incompatible drive model overrides', function (): void {
    $config = new DriveConfig(new Repository(['vaulter-drive' => ['models' => ['upload_session' => stdClass::class]]]));

    expect(fn () => $config->model('upload_session'))->toThrow(InvalidConfigurationException::class);
});
