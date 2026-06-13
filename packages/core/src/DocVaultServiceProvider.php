<?php

declare(strict_types=1);

namespace DocVault;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DocVaultServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('docvault')
            ->hasConfigFile()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('axioma-studio/doc-vault');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DocVaultManager::class);
        $this->app->alias(DocVaultManager::class, 'docvault');
    }
}
