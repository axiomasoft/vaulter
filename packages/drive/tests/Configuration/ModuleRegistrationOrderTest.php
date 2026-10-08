<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Providers\BindingsRegistrar as DriveBindingsRegistrar;
use Vaulter\Media\Configuration\DedupScope;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Providers\BindingsRegistrar as CoreBindingsRegistrar;

it('retains module defaults and normalizes legacy settings when modules register before core', function (): void {
    $previousContainer = Container::getInstance();
    try {
        $app = new Application;
        $repository = new Repository(['vaulter' => [
            'uploads' => ['max_bytes' => 2048],
            'dedup' => ['scope' => 'storage'],
        ]]);
        $app->instance('config', $repository);

        (new DriveBindingsRegistrar($app))->register();
        $normalizer = $app->make(ConfigNormalizer::class);
        (new VaulterMediaServiceProvider($app))->register();
        (new CoreBindingsRegistrar)->register($app);

        expect($app->make(ConfigNormalizer::class))->toBe($normalizer)
            ->and($normalizer->packageDefaults())->toHaveKeys(['vaulter-drive', 'vaulter-media']);

        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            if ($level !== E_USER_DEPRECATED) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $normalizer->normalize(config: $repository, packageDefaults: $normalizer->packageDefaults());
        } finally {
            restore_error_handler();
        }

        expect($app->make(DriveConfig::class)->maxFileSize())->toBe(2048)
            ->and($app->make(MediaConfig::class)->dedupScope())->toBe(DedupScope::Drive)
            ->and($deprecations)->toHaveCount(2);
    } finally {
        Container::setInstance($previousContainer);
    }
});
