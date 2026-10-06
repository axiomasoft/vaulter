<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Configuration\MediaConfig;

it('reads canonical media settings live and uses drive dedup scope', function (): void {
    $repository = new Repository(['vaulter-media' => require __DIR__.'/../../config/vaulter-media.php']);
    $config = new MediaConfig($repository);

    expect($config->disk())->toBe('local')
        ->and($config->dedupScope())->toBe('tenant')
        ->and($config->storageDriver())->toBe(FlysystemStorageDriver::class);

    $repository->set('vaulter-media.disk', 'archive');
    $repository->set('vaulter-media.dedup.scope', 'drive');
    $repository->set('vaulter-media.gc', ['grace_hours' => 48]);

    expect($config->disk())->toBe('archive')
        ->and($config->dedupScope())->toBe('drive')
        ->and($config->orphanGraceHours())->toBe(48)
        ->and($config->gcGraceHours(2))->toBe(2);
});

it('normalizes the storage scope from the canonical media environment setting without legacy config', function (): void {
    $hadValue = array_key_exists('VAULTER_DEDUP_SCOPE', $_SERVER);
    $previousValue = $_SERVER['VAULTER_DEDUP_SCOPE'] ?? null;
    $_SERVER['VAULTER_DEDUP_SCOPE'] = 'storage';

    try {
        $repository = new Repository(['vaulter-media' => require __DIR__.'/../../config/vaulter-media.php']);

        expect($repository->has('vaulter.dedup.scope'))->toBeFalse()
            ->and($repository->get('vaulter-media.dedup.scope'))->toBe('storage')
            ->and((new MediaConfig($repository))->dedupScope())->toBe('drive');
    } finally {
        if ($hadValue) {
            $_SERVER['VAULTER_DEDUP_SCOPE'] = $previousValue;
        } else {
            unset($_SERVER['VAULTER_DEDUP_SCOPE']);
        }
    }
});

it('normalizes canonical storage scope changes on the live repository after boot', function (): void {
    $repository = $this->app['config'];
    $coreConfig = $repository->get('vaulter', []);
    unset($coreConfig['dedup']['scope']);
    $repository->set('vaulter', $coreConfig);
    $config = $this->app->make(MediaConfig::class);

    $repository->set('vaulter-media.dedup.scope', 'storage');

    expect($repository->has('vaulter.dedup.scope'))->toBeFalse()
        ->and($config->dedupScope())->toBe('drive');

    $repository->set('vaulter-media.dedup.scope', 'drive');

    expect($config->dedupScope())->toBe('drive');
});

it('floors grace windows and rejects incompatible CAS implementations', function (): void {
    $repository = new Repository(['vaulter-media' => ['gc' => ['grace_hours' => 0], 'content_store' => stdClass::class]]);
    $config = new MediaConfig($repository);

    expect($config->gcGraceHours())->toBe(1)
        ->and(fn () => $config->contentStore())->toThrow(InvalidConfigurationException::class);
});
