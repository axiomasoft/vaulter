<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Providers;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Laravel\VaulterPluginRegistrar;
use Vaulter\Webhooks\WebhooksModule;

/** Registers the native plugin and its Vaulter domain runtime. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class WebhooksPackageRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        VaulterPluginRegistrar::register($app, WebhooksModule::class, dirname(__DIR__, 2).'/composer.json');
    }

    public function boot(Application $app): void {}
}
