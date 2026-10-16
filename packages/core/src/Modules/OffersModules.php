<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * For a package service provider: offer the package's manifest from `register()`. The provider
 * registers nothing else itself — Laravel discovers it even when its module is inactive.
 *
 * @property Application $app
 */
#[Spi(SpiKind::Implement)]
trait OffersModules
{
    /** @param class-string<VaulterModule> $manifest */
    protected function offerModule(string $manifest): void
    {
        ModuleCatalog::of($this->app)->offer($manifest);
    }
}
