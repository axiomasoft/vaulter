<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Handlers\Builtin\PreviewHandler;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Profiles\DefaultProfile;
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

    expect($config->maxFileSize())->toBe(1025)
        ->and($config->resumableEnabled())->toBeFalse();
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

it('owns the config sections of the drive concepts under vaulter-drive (P4.11)', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/vaulter-drive.php';

    expect($config)->toHaveKeys([
        'access', 'changes', 'downloads', 'drives', 'features', 'handlers', 'listing', 'models', 'profiles', 'scan', 'schedule', 'sharing', 'trash', 'uploads',
    ])
        ->and(array_keys($config['models']))->toEqualCanonicalizing([
            'drive', 'node', 'file', 'file_revision', 'file_revision_blob', 'grant', 'node_share', 'activity',
            'scan_obligation', 'scan_request', 'scan_evidence', 'scan_policy_state', 'scan_backfill_run', 'upload_session', 'upload_part',
        ])
        ->and($config['schedule'])->toHaveKey('changes_prune', 'dailyAt:03:20')
        ->and($config['features'])->toBe(['grants' => true, 'activity' => true, 'changes' => true, 'share_links' => false, 'public_listing' => false]);
});

it('reads the handler, profile and feature keys of the drive root', function (): void {
    $repository = new Repository(['vaulter-drive' => require __DIR__.'/../../config/vaulter-drive.php']);
    $config = new DriveConfig($repository);

    expect($config->profileProviders())->toBe([])
        ->and($config->defaultProfile())->toBe(DefaultProfile::class)
        ->and($config->handlers())->toBe([])
        ->and($config->featureFlag('grants'))->toBeTrue()
        ->and($config->featureFlag('share_links'))->toBeFalse()
        ->and($config->featureFlag('unknown'))->toBeFalse()
        ->and($config->drivesDeleteWithOwner())->toBeFalse();

    $repository->set('vaulter-drive.handlers', [PreviewHandler::class, 'Not\\A\\Handler', 7]);
    $repository->set('vaulter-drive.drives.delete_with_owner', true);
    $repository->set('vaulter-drive.features.share_links', true);

    expect($config->handlers())->toBe([PreviewHandler::class])
        ->and($config->drivesDeleteWithOwner())->toBeTrue()
        ->and($config->featureFlag('share_links'))->toBeTrue();
});

it('rejects a profile list that is not a list of ProfileProvider classes', function (mixed $providers): void {
    $repository = new Repository(['vaulter-drive' => ['profiles' => ['providers' => $providers]]]);

    (new DriveConfig($repository))->profileProviders();
})->with([
    'a map' => [['a' => DefaultProfile::class]],
    'a foreign class' => [[stdClass::class]],
    'a scalar' => ['nope'],
])->throws(InvalidConfigurationException::class, 'vaulter-drive.profiles.providers');

it('resolves a swappable drive model from vaulter-drive.models and refuses a foreign class', function (): void {
    $repository = new Repository(['vaulter-drive' => require __DIR__.'/../../config/vaulter-drive.php']);
    $config = new DriveConfig($repository);
    $subclass = new class extends Node {};

    expect($config->modelClass('node'))->toBe(Node::class);

    $repository->set('vaulter-drive.models.node', $subclass::class);

    expect($config->modelClass('node'))->toBe($subclass::class);

    $repository->set('vaulter-drive.models.node', stdClass::class);

    expect(fn () => $config->modelClass('node'))->toThrow(InvalidConfigurationException::class, 'vaulter-drive.models.node');
    expect(fn () => $config->modelClass('outbox'))->toThrow(InvalidConfigurationException::class, 'vaulter-drive.models.outbox');
});
