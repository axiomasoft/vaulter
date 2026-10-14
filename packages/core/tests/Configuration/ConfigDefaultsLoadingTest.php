<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
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

it('keeps cached registration unchanged and fills defaults in either provider order', function (bool $moduleFirst): void {
    $previousContainer = Container::getInstance();
    $config = new Repository([
        'vaulter' => ['database' => ['table_prefix' => 'host_']],
        'vaulter-media' => ['disk' => 'host-disk'] + require __DIR__.'/../../../media/config/vaulter-media.php',
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
        // Registering a provider only offers its module: the cached config is not touched.
        expect($config->all())->toBe($cached);

        // The defaults are registered when the module graph is applied, after every provider registered.
        $application->singleton(Kernel::class, Illuminate\Foundation\Http\Kernel::class);
        $application->singleton(ExceptionHandler::class, Handler::class);
        $application->boot();
        $normalizer = $application->make(ConfigNormalizer::class);
        $defaults = $normalizer->packageDefaults();
        expect(array_keys($defaults))->toEqualCanonicalizing(['vaulter', 'vaulter-media']);

        $normalizer->normalize($config, $defaults);

        expect($config->get('vaulter.database.table_prefix'))->toBe('host_')
            ->and($config->get('vaulter-media.disk'))->toBe('host-disk')
            ->and($config->get('vaulter.ids.strategy'))->toBe($defaults['vaulter']['ids']['strategy'])
            ->and($config->has('vaulter-drive'))->toBeFalse();
    } finally {
        Container::setInstance($previousContainer);
    }
})->with([false, true]);

it('fills missing nested defaults for partially published uncached config', function (): void {
    $previousContainer = Container::getInstance();

    try {
        $application = new Application(__DIR__);
        $config = new Repository(['vaulter' => ['database' => ['table_prefix' => 'host_']]]);
        $application->instance('config', $config);
        $application->register(VaulterServiceProvider::class);
        $application->singleton(Kernel::class, Illuminate\Foundation\Http\Kernel::class);
        $application->singleton(ExceptionHandler::class, Handler::class);
        $application->boot();
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
