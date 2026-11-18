<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\AzGuard\AzGuardModule;
use Vaulter\Laravel\VaulterPluginRegistrar;

/** Registers the native plugin and its Vaulter domain runtime. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class AzGuardPackageRegistrar implements PackageRegistrar
{
    /** @var list<string> Associative maps where an explicit host configuration replaces defaults wholesale. */
    private const array REPLACEMENT_MAPS = [
        'vaulter-azguard.permissions',
    ];

    #[Override]
    public function register(Application $app): void
    {
        /** @var array<string, mixed> $preservedMaps */
        $preservedMaps = [];
        $config = $app->bound('config') ? $app->make('config') : null;
        if ($config instanceof Repository) {
            foreach (self::REPLACEMENT_MAPS as $key) {
                if ($config->has($key)) {
                    $preservedMaps[$key] = $config->get($key);
                }
            }
        }

        VaulterPluginRegistrar::register($app, AzGuardModule::class, dirname(__DIR__, 2).'/composer.json');

        if ($config instanceof Repository) {
            foreach ($preservedMaps as $key => $value) {
                $config->set($key, $value);
            }
        }
    }

    #[Override]
    public function boot(Application $app): void {}
}
