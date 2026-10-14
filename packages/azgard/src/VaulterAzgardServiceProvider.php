<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

/**
 * The AzGuard bridge only offers {@see AzgardModule}; the core module kernel registers its config, the
 * AzgardGuard seam and its doctor check while the module is active.
 */
final class VaulterAzgardServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(AzgardModule::class);
    }
}
