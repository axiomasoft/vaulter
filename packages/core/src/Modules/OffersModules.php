<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;

/**
 * For a package service provider: offer the package's manifest from `register()`. The provider
 * registers nothing else itself — Laravel discovers it even when its module is inactive.
 *
 * @property Application $app
 */
#[Spi(SpiKind::Implement)]
trait OffersModules
{
    private function moduleProviderRegistered(): bool
    {
        return $this->app->getProviders(static::class) !== [];
    }

    // A forced registration may replace the provider object before its first boot.
    private function moduleProviderBooted(): bool
    {
        return $this->app->bound(static::class.':booted');
    }

    private function markModuleProviderBooted(): void
    {
        $this->app->instance(static::class.':booted', true);
    }

    /** @param class-string<ExtensionPlugin> $manifest */
    protected function offerModule(string $manifest): void
    {
        ModuleCatalog::of($this->app)->offer($manifest);
    }
}
