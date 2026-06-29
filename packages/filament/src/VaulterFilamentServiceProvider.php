<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class VaulterFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-filament')
            ->hasConfigFile();
    }
}
