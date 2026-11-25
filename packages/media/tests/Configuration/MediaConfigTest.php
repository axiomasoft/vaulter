<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Configuration\DedupScope;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Models\Cas\Blob;

it('constructs immutable media options from config arrays and supports drive dedup scope', function (): void {
    $defaults = MediaOptions::fromArray(require __DIR__.'/../../config/vaulter-media.php');

    expect($defaults->disk())->toBe('local')
        ->and($defaults->dedupScope())->toBe(DedupScope::Tenant)
        ->and($defaults->storageDriver())->toBe(FlysystemStorageDriver::class);

    $custom = MediaOptions::fromArray([
        'disk' => 'archive',
        'dedup' => ['scope' => 'drive'],
        'gc' => ['grace_hours' => 48],
    ]);

    expect($custom->disk())->toBe('archive')
        ->and($custom->dedupScope())->toBe(DedupScope::Drive)
        ->and($custom->orphanGraceHours())->toBe(48)
        ->and($custom->gcGraceHours(2))->toBe(2);
});

it('reads the dedup scope from the canonical media environment setting', function (): void {
    $hadValue = array_key_exists('VAULTER_DEDUP_SCOPE', $_SERVER);
    $previousValue = $_SERVER['VAULTER_DEDUP_SCOPE'] ?? null;
    $_SERVER['VAULTER_DEDUP_SCOPE'] = 'drive';

    try {
        /** @var array<string, mixed> $raw */
        $raw = require __DIR__.'/../../config/vaulter-media.php';

        expect($raw['dedup']['scope'])->toBe('drive')
            ->and(MediaOptions::fromArray($raw)->dedupScope())->toBe(DedupScope::Drive);
    } finally {
        if ($hadValue) {
            $_SERVER['VAULTER_DEDUP_SCOPE'] = $previousValue;
        } else {
            unset($_SERVER['VAULTER_DEDUP_SCOPE']);
        }
    }
});

it('resolves MediaOptions lazily in the container when config is overridden before first resolution', function (): void {
    $this->app['config']->set('vaulter-media.dedup.scope', 'drive');
    $this->app->forgetInstance(MediaOptions::class);

    expect($this->app->make(MediaOptions::class)->dedupScope())->toBe(DedupScope::Drive);
});

it('floors grace windows, rejects unknown keys, and rejects incompatible CAS implementations', function (): void {
    $floored = MediaOptions::fromArray(['gc' => ['grace_hours' => 0]]);
    expect($floored->gcGraceHours())->toBe(1);

    expect(fn (): MediaOptions => MediaOptions::fromArray(['content_store' => stdClass::class]))
        ->toThrow(InvalidConfiguration::class)
        ->and(fn (): MediaOptions => MediaOptions::fromArray(['unknown_key' => true]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-media.unknown_key');
});

it('refuses the global scope and unknown scopes', function (mixed $value, string $message): void {
    expect(fn (): MediaOptions => MediaOptions::fromArray(['dedup' => ['scope' => $value]]))
        ->toThrow(InvalidConfiguration::class, $message);
})->with([
    'global' => ['global', 'must not be shared across tenants'],
    'unknown' => ['everything', 'tenant, drive or off'],
    'not a string' => [[], 'tenant, drive or off'],
]);

it('accepts off', function (): void {
    expect(MediaOptions::fromArray(['dedup' => ['scope' => 'off']])->dedupScope())->toBe(DedupScope::Off);
});

it('reads the swappable blob model from vaulter-media.models.blob and refuses a class that is not a blob', function (): void {
    /** @var array<string, mixed> $base */
    $base = require __DIR__.'/../../config/vaulter-media.php';

    expect(MediaOptions::fromArray($base)->blobModel())->toBe(Blob::class);

    $custom = new class extends Blob {};
    expect(MediaOptions::fromArray([...$base, 'models' => ['blob' => $custom::class]])->blobModel())->toBe($custom::class);

    expect(fn (): MediaOptions => MediaOptions::fromArray([...$base, 'models' => ['blob' => stdClass::class]]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-media.models.blob');

    expect(fn (): MediaOptions => MediaOptions::fromArray([...$base, 'models' => ['blob' => 42]]))
        ->toThrow(InvalidConfiguration::class, 'must be a class name');
});
