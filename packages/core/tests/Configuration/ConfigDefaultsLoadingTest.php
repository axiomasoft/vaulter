<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

it('defers package config reads until defaults are requested and loads each path once', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-defaults-');
    if ($path === false) {
        throw new RuntimeException('Cannot create package config fixture.');
    }

    try {
        file_put_contents($path, '<?php throw new RuntimeException("Config read during registration.");');
        $normalizer = new ConfigNormalizer;
        $normalizer->registerDefaults(root: 'vaulter', path: $path);

        file_put_contents($path, '<?php return ["database" => ["table_prefix" => "fixture_"]];');
        expect($normalizer->packageDefaults())->toBe(['vaulter' => ['database' => ['table_prefix' => 'fixture_']]]);

        file_put_contents($path, '<?php throw new RuntimeException("Config read more than once.");');
        $normalizer->registerDefaults(root: 'vaulter', path: $path);
        expect($normalizer->packageDefaults())->toBe(['vaulter' => ['database' => ['table_prefix' => 'fixture_']]]);
    } finally {
        unlink($path);
    }
});

it('validates deferred package defaults when they are loaded', function (string $source, string $message): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-defaults-');
    if ($path === false) {
        throw new RuntimeException('Cannot create package config fixture.');
    }

    try {
        file_put_contents($path, $source);
        $normalizer = new ConfigNormalizer;
        $normalizer->registerDefaults(root: 'vaulter', path: $path);

        expect(fn (): array => $normalizer->packageDefaults())->toThrow(InvalidConfigurationException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    ['<?php return false;', 'must return an array'],
    ['<?php return [0 => "invalid"];', 'must use string root keys'],
]);

it('reloads a replacement config path while preserving package registration order', function (): void {
    $firstPath = tempnam(sys_get_temp_dir(), 'vaulter-defaults-');
    $secondPath = tempnam(sys_get_temp_dir(), 'vaulter-defaults-');
    if ($firstPath === false || $secondPath === false) {
        throw new RuntimeException('Cannot create package config fixtures.');
    }

    try {
        file_put_contents($firstPath, '<?php return ["value" => "first"];');
        file_put_contents($secondPath, '<?php return ["value" => "replacement"];');
        $normalizer = new ConfigNormalizer;
        $normalizer->registerDefaults(root: 'vaulter', path: $firstPath);
        $normalizer->registerDefaults(root: 'vaulter-media', path: $firstPath);
        $normalizer->packageDefaults();
        $normalizer->registerDefaults(root: 'vaulter', path: $secondPath);

        expect($normalizer->packageDefaults())->toBe([
            'vaulter' => ['value' => 'replacement'],
            'vaulter-media' => ['value' => 'first'],
        ]);
    } finally {
        unlink($firstPath);
        unlink($secondPath);
    }
});

it('keeps cached registration unchanged and normalizes legacy values using exact defaults in either provider order', function (bool $moduleFirst): void {
    $previousContainer = Container::getInstance();
    $config = new Repository([
        'vaulter' => ['table_prefix' => 'host_', 'storage' => ['default_disk' => 'host-disk'], 'uploads' => ['chunk_size' => 543]],
        'vaulter-media' => require __DIR__.'/../../../media/config/vaulter-media.php',
    ]);
    $cached = $config->all();

    try {
        $application = new class(__DIR__) extends Application
        {
            public function configurationIsCached(): bool
            {
                return true;
            }
        };
        $application->instance('config', $config);
        $providers = $moduleFirst
            ? [VaulterMediaServiceProvider::class, VaulterServiceProvider::class]
            : [VaulterServiceProvider::class, VaulterMediaServiceProvider::class];
        foreach ($providers as $provider) {
            $application->register($provider);
        }
        expect($config->all())->toBe($cached);

        $normalizer = $application->make(ConfigNormalizer::class);
        $defaults = $normalizer->packageDefaults();
        expect(array_keys($defaults))->toBe($moduleFirst ? ['vaulter-media', 'vaulter'] : ['vaulter', 'vaulter-media']);

        set_error_handler(static fn (int $severity): bool => $severity === E_USER_DEPRECATED);
        try {
            $normalizer->normalize($config, $defaults);
        } finally {
            restore_error_handler();
        }

        expect($config->get('vaulter.database.table_prefix'))->toBe('host_')
            ->and($config->get('vaulter-media.disk'))->toBe('host-disk')
            ->and($config->get('vaulter.ids.strategy'))->toBe($defaults['vaulter']['ids']['strategy'])
            ->and($config->get('vaulter.uploads.chunk_size'))->toBe(543)
            ->and($config->has('vaulter-drive'))->toBeFalse();
    } finally {
        Container::setInstance($previousContainer);
    }
})->with([false, true]);

it('rejects a cached canonical override conflicting with a legacy key after lazy loading', function (): void {
    $normalizer = new ConfigNormalizer;
    $normalizer->registerDefaults(root: 'vaulter', path: __DIR__.'/../../config/vaulter.php');
    $config = new Repository(['vaulter' => ['table_prefix' => 'legacy_', 'database' => ['table_prefix' => 'canonical_']]]);

    $normalizer->normalize($config, $normalizer->packageDefaults());
})->throws(InvalidConfigurationException::class, 'Conflicting config keys "vaulter.table_prefix" and "vaulter.database.table_prefix"');

it('fills missing nested defaults for partially published uncached config', function (): void {
    $previousContainer = Container::getInstance();

    try {
        $application = new Application(__DIR__);
        $config = new Repository(['vaulter' => ['database' => ['table_prefix' => 'host_']]]);
        $application->instance('config', $config);
        $application->register(VaulterServiceProvider::class);
        $normalizer = $application->make(ConfigNormalizer::class);
        $defaults = $normalizer->packageDefaults();
        $normalizer->normalize($config, $defaults);

        expect($config->get('vaulter.database.table_prefix'))->toBe('host_')
            ->and($config->has('vaulter.database.connection'))->toBeTrue()
            ->and($config->get('vaulter.database.connection'))->toBe($defaults['vaulter']['database']['connection']);
    } finally {
        Container::setInstance($previousContainer);
    }
});
