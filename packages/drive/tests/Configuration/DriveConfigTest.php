<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Drive\Configuration\ChangesOptions;
use Vaulter\Drive\Configuration\DownloadsOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\ListingOptions;
use Vaulter\Drive\Configuration\ScanOptions;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Handlers\Builtin\PreviewHandler;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Profiles\DefaultProfile;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Models\Cas\Blob;

it('reads drive settings cleanly and uses the canonical byte ceiling', function (): void {
    $raw = require __DIR__.'/../../config/vaulter-drive.php';
    $options = DriveOptions::fromArray($raw);

    expect($options->uploads->maxFileSize)->toBe(5_368_709_120)
        ->and($options->uploads->resumable->enabled)->toBeTrue()
        ->and($options->model('upload_session'))->toBe(UploadSession::class)
        ->and($options->model('upload_part'))->toBe(UploadPart::class);

    $raw['uploads']['max_file_size'] = 1025;
    $raw['uploads']['resumable']['enabled'] = false;
    $updated = DriveOptions::fromArray($raw);

    expect($updated->uploads->maxFileSize)->toBe(1025)
        ->and($updated->uploads->resumable->enabled)->toBeFalse();
});

it('preserves resumable boolean casting for canonical settings', function (mixed $value, bool $enabled): void {
    expect(DriveOptions::fromArray([])->uploads->resumable->enabled)->toBeTrue();

    $options = DriveOptions::fromArray(['uploads' => ['resumable' => ['enabled' => $value]]]);

    expect($options->uploads->resumable->enabled)->toBe($enabled);
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
    $options = DriveOptions::fromArray(['uploads' => ['resumable' => [
        'chunk_size' => 0, 'max_chunk_size' => -2, 'session_ttl_hours' => 0,
        'max_active_sessions_per_actor' => -1,
        'metadata' => ['allowed_keys' => ['filename', '', false], 'max_value_length' => 0],
    ]]]);

    expect($options->uploads->resumable->chunkSize)->toBe(1)
        ->and($options->uploads->resumable->maxChunkSize)->toBe(0)
        ->and($options->uploads->resumable->sessionTtlHours)->toBe(1)
        ->and($options->uploads->resumable->maxActiveSessionsPerActor)->toBe(0)
        ->and($options->uploads->resumable->metadata->allowedKeys)->toBe(['filename'])
        ->and($options->uploads->resumable->metadata->maxValueLength)->toBe(1);
});

it('rejects incompatible drive model overrides', function (): void {
    expect(fn (): DriveOptions => DriveOptions::fromArray(['models' => ['upload_session' => stdClass::class]]))
        ->toThrow(InvalidConfiguration::class);
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
    $raw = require __DIR__.'/../../config/vaulter-drive.php';
    $options = DriveOptions::fromArray($raw);

    expect($options->profiles->providers)->toBe([])
        ->and($options->profiles->default)->toBe(DefaultProfile::class)
        ->and($options->handlers)->toBe([])
        ->and($options->features->flag('grants'))->toBeTrue()
        ->and($options->features->flag('share_links'))->toBeFalse()
        ->and($options->features->flag('unknown'))->toBeFalse()
        ->and($options->drives->deleteWithOwner)->toBeFalse();

    $raw['handlers'] = [PreviewHandler::class, 'Not\\A\\Handler', 7];
    $raw['drives']['delete_with_owner'] = true;
    $raw['features']['share_links'] = true;
    $updated = DriveOptions::fromArray($raw);

    expect($updated->handlers)->toBe([PreviewHandler::class])
        ->and($updated->drives->deleteWithOwner)->toBeTrue()
        ->and($updated->features->flag('share_links'))->toBeTrue();
});

it('rejects a profile list that is not a list of ProfileProvider classes', function (mixed $providers): void {
    DriveOptions::fromArray(['profiles' => ['providers' => $providers]]);
})->with([
    'a map' => [['a' => DefaultProfile::class]],
    'a foreign class' => [[stdClass::class]],
    'a scalar' => ['nope'],
])->throws(InvalidConfiguration::class, 'vaulter-drive.profiles.providers');

it('resolves a swappable drive model from vaulter-drive.models and refuses a foreign class', function (): void {
    $raw = require __DIR__.'/../../config/vaulter-drive.php';
    $subclass = new class extends Node {};

    expect(DriveOptions::fromArray($raw)->model('node'))->toBe(Node::class);

    $raw['models']['node'] = $subclass::class;
    expect(DriveOptions::fromArray($raw)->model('node'))->toBe($subclass::class);

    expect(fn (): DriveOptions => DriveOptions::fromArray(['models' => ['node' => stdClass::class]]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-drive.models.node');
    expect(fn (): DriveOptions => DriveOptions::fromArray(['models' => ['outbox' => stdClass::class]]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-drive.models.outbox');
});

it('resolves the upload models, the blob model of the media package and the compiled drive sections', function (): void {
    $raw = require __DIR__.'/../../config/vaulter-drive.php';
    $media = MediaOptions::fromArray(require InstalledVersions::getInstallPath('axiomasoft/vaulter-media').'/config/vaulter-media.php');
    $options = DriveOptions::fromArray($raw);
    $repository = new Repository(['vaulter-drive' => $raw]);

    expect($options->model('upload_session'))->toBe(UploadSession::class)
        ->and($options->model('upload_part'))->toBe(UploadPart::class)
        ->and($media->blobModel)->toBe(Blob::class)
        ->and($options->featureEnabled('changes'))->toBe($repository->get('vaulter-drive.features.changes') === true)
        ->and($options->access->permissionDriver)->toBeString()
        ->and($options->scan)->toBeInstanceOf(ScanOptions::class)
        ->and($options->listing)->toBeInstanceOf(ListingOptions::class)
        ->and($options->sharing)->toBeInstanceOf(SharingOptions::class)
        ->and($options->downloads)->toBeInstanceOf(DownloadsOptions::class)
        ->and($options->changes)->toBeInstanceOf(ChangesOptions::class);

    $raw['features']['changes'] = false;
    expect(DriveOptions::fromArray($raw)->featureEnabled('changes'))->toBeFalse();

    $raw['features']['changes'] = true;
    expect(DriveOptions::fromArray($raw)->featureEnabled('changes'))->toBeTrue();
});
