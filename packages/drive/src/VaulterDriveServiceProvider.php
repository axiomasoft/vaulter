<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The drive package only offers {@see DriveModule}; the core module kernel registers what the module
 * declares, so a host that switches the drive off (`vaulter.modules.drive = false`) gets none of it.
 */
#[Api]
final class VaulterDriveServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-drive')->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->offerModule(DriveModule::class);
    }
}
