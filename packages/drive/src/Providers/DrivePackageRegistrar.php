<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Drive\DriveModule;
use Vaulter\Laravel\VaulterPluginRegistrar;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see DriveModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DrivePackageRegistrar implements PackageRegistrar
{
    /** @var list<string> Associative maps where an explicit host configuration replaces defaults wholesale. */
    private const array REPLACEMENT_MAPS = [
        'vaulter-drive.handlers',
        'vaulter-drive.profiles.providers',
        'vaulter-drive.access.permission_drivers',
        'vaulter-drive.access.gate.abilities',
        'vaulter-drive.access.spatie.permissions',
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

        VaulterPluginRegistrar::register($app, DriveModule::class, dirname(__DIR__, 2).'/composer.json');

        if ($config instanceof Repository) {
            foreach ($preservedMaps as $key => $value) {
                $config->set($key, $value);
            }
        }

    }

    #[Override]
    public function boot(Application $app): void {}
}
