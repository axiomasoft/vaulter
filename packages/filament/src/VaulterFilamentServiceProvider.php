<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Illuminate\Contracts\Container\Container;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Filament\Configuration\FilamentConfig;

final class VaulterFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-filament')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(FilamentConfig::class, fn (Container $app): FilamentConfig => new FilamentConfig($app->make('config')));
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-filament', path: __DIR__.'/../config/vaulter-filament.php');
    }
}
