<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

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
    #[Override]
    public function register(Application $app): void
    {
        VaulterPluginRegistrar::register($app, DriveModule::class, dirname(__DIR__, 2).'/composer.json');
    }

    #[Override]
    public function boot(Application $app): void {}
}
