<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Modules\OffersModules;

/**
 * The Filament package only offers {@see FilamentModule}; the panel plugin {@see VaulterPlugin} stays an
 * explicit host registration on its panel.
 */
final class VaulterFilamentServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-filament');
    }

    public function packageRegistered(): void
    {
        $this->offerModule(FilamentModule::class);
    }
}
