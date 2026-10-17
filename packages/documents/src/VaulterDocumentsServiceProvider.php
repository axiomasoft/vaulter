<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The documents package only offers {@see DocumentsModule}: the core module kernel registers its use cases,
 * revisions, comments, export and HTTP routes while the module is active.
 */
#[Api]
final class VaulterDocumentsServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-documents')->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->offerModule(DocumentsModule::class);
    }
}
