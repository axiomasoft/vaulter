<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The collaboration package only offers {@see CollaborationModule}; the core module kernel registers what the module
 * declares, so a host that leaves the opt-in module off (`vaulter.modules.collaboration`) gets none of it.
 */
#[Api]
final class VaulterCollaborationServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-collaboration')->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->offerModule(CollaborationModule::class);
    }
}
