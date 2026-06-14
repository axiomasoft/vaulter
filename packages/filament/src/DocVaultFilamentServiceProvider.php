<?php

declare(strict_types=1);

namespace DocVault\Filament;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DocVaultFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('docvault-filament')
            ->hasConfigFile();
    }
}
