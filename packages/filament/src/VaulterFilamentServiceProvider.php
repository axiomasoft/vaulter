<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The Filament package only offers {@see FilamentModule}; the panel plugin {@see VaulterPlugin} stays an
 * explicit host registration on its panel.
 */
#[Api]
final class VaulterFilamentServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(FilamentModule::class);
    }
}
