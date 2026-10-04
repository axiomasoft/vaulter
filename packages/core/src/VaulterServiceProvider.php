<?php

declare(strict_types=1);

namespace Vaulter;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Providers\AccessRegistrar;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\ConsoleRegistrar;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\Providers\HttpRegistrar;

final class VaulterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasConfigFile()
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('axiomasoft/vaulter');
            });
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar)->register($this->app);
        (new AccessRegistrar)->register($this->app);
        (new ConsoleRegistrar)->register($this->app);
    }

    public function packageBooted(): void
    {
        (new HttpRegistrar)->boot($this->app);
        (new AccessRegistrar)->boot($this->app);
        (new EventsRegistrar)->boot($this->app);
        (new BindingsRegistrar)->boot($this->app);
    }
}
