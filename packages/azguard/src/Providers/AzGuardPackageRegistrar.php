<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Providers;

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
    #[Override]
    public function register(Application $app): void
    {
        VaulterPluginRegistrar::register($app, AzGuardModule::class, dirname(__DIR__, 2).'/composer.json');
    }

    #[Override]
    public function boot(Application $app): void {}
}
