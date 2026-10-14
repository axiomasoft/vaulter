<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Foundation\Application;

/**
 * For a package service provider: offer the package's manifest from `register()`. The provider
 * registers nothing else itself — Laravel discovers it even when its module is inactive.
 *
 * @property Application $app
 */
trait OffersModules
{
    /** @param class-string<VaulterModule> $manifest */
    protected function offerModule(string $manifest): void
    {
        ModuleCatalog::of($this->app)->offer($manifest);
    }
}
