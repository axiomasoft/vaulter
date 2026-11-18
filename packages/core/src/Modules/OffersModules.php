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
    /** @param class-string<ExtensionPlugin> $manifest */
    protected function offerModule(string $manifest): void
    {
        ModuleCatalog::of($this->app)->offer($manifest);
    }
}
