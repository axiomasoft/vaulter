<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Illuminate\Support\ServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The AzGuard bridge only offers {@see AzGuardModule}; the core module kernel registers its config, the
 * AzGuardGuard seam and its doctor check while the module is active.
 */
#[Api]
final class VaulterAzGuardServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(AzGuardModule::class);
    }
}
