<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Configuration\DedupScope;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Models\Cas\Blob;

it('reads canonical media settings live and uses drive dedup scope', function (): void {
    $repository = new Repository(['vaulter-media' => require __DIR__.'/../../config/vaulter-media.php']);
    $config = new MediaConfig($repository);

    expect($config->disk())->toBe('local')
        ->and($config->dedupScope())->toBe(DedupScope::Tenant)
        ->and($config->storageDriver())->toBe(FlysystemStorageDriver::class);

    $repository->set('vaulter-media.disk', 'archive');
    $repository->set('vaulter-media.dedup.scope', 'drive');
    $repository->set('vaulter-media.gc', ['grace_hours' => 48]);

    expect($config->disk())->toBe('archive')
        ->and($config->dedupScope())->toBe(DedupScope::Drive)
        ->and($config->orphanGraceHours())->toBe(48)
        ->and($config->gcGraceHours(2))->toBe(2);
});

it('reads the dedup scope from the canonical media environment setting', function (): void {
    $hadValue = array_key_exists('VAULTER_DEDUP_SCOPE', $_SERVER);
    $previousValue = $_SERVER['VAULTER_DEDUP_SCOPE'] ?? null;
    $_SERVER['VAULTER_DEDUP_SCOPE'] = 'drive';

    try {
        $repository = new Repository(['vaulter-media' => require __DIR__.'/../../config/vaulter-media.php']);

        expect($repository->get('vaulter-media.dedup.scope'))->toBe('drive')
            ->and((new MediaConfig($repository))->dedupScope())->toBe(DedupScope::Drive);
    } finally {
        if ($hadValue) {
            $_SERVER['VAULTER_DEDUP_SCOPE'] = $previousValue;
        } else {
            unset($_SERVER['VAULTER_DEDUP_SCOPE']);
        }
    }
});

it('reads canonical scope changes on the live repository after boot', function (): void {
    $repository = $this->app['config'];
    $config = $this->app->make(MediaConfig::class);

    $repository->set('vaulter-media.dedup.scope', 'drive');

    expect($config->dedupScope())->toBe(DedupScope::Drive);

    $repository->set('vaulter-media.dedup.scope', 'off');

    expect($config->dedupScope())->toBe(DedupScope::Off);
});

it('floors grace windows and rejects incompatible CAS implementations', function (): void {
    $repository = new Repository(['vaulter-media' => ['gc' => ['grace_hours' => 0], 'content_store' => stdClass::class]]);
    $config = new MediaConfig($repository);

    expect($config->gcGraceHours())->toBe(1)
        ->and(fn () => $config->contentStore())->toThrow(InvalidConfigurationException::class);
});

it('refuses the global scope and unknown scopes', function (mixed $value, string $message): void {
    $config = new MediaConfig(new Repository(['vaulter-media' => ['dedup' => ['scope' => $value]]]));

    expect(fn () => $config->dedupScope())->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'global' => ['global', 'must not be shared across tenants'],
    'unknown' => ['everything', 'tenant, drive or off'],
    'not a string' => [[], 'tenant, drive or off'],
]);

it('accepts off', function (): void {
    expect((new MediaConfig(new Repository(['vaulter-media' => ['dedup' => ['scope' => 'off']]])))->dedupScope())->toBe(DedupScope::Off);
});

it('reads the swappable blob model from vaulter-media.models.blob and refuses a class that is not a blob (D6)', function (): void {
    $repository = new Repository(['vaulter-media' => require __DIR__.'/../../config/vaulter-media.php']);
    $config = new MediaConfig($repository);

    expect($config->blobModel())->toBe(Blob::class);

    $custom = new class extends Blob {};
    $repository->set('vaulter-media.models.blob', $custom::class);
    expect($config->blobModel())->toBe($custom::class);

    $repository->set('vaulter-media.models.blob', stdClass::class);
    expect(fn () => $config->blobModel())->toThrow(InvalidConfigurationException::class, 'vaulter-media.models.blob');

    $repository->set('vaulter-media.models.blob', 42);
    expect(fn () => $config->blobModel())->toThrow(InvalidConfigurationException::class, 'must be a class name');
});
