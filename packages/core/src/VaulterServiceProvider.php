<?php

declare(strict_types=1);

namespace Vaulter;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Providers\AccessRegistrar;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\ConsoleRegistrar;
use Vaulter\Providers\DoctorRegistrar;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\Providers\HttpRegistrar;
use Vaulter\Providers\SchedulingRegistrar;

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
            ->hasRoute('api');
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar)->register($this->app);
        $normalizer = $this->app->make(ConfigNormalizer::class);
        $normalizer->registerDefaults('vaulter', __DIR__.'/../config/vaulter.php');
        $this->app->booting(function (): void {
            $normalizer = $this->app->make(ConfigNormalizer::class);
            $normalizer->normalize($this->app->make('config'), $normalizer->packageDefaults());
        });
        (new AccessRegistrar)->register($this->app);
        (new EventsRegistrar)->register($this->app);
        (new ConsoleRegistrar)->register($this->app);
        (new DoctorRegistrar)->register($this->app);
        (new HttpRegistrar)->register($this->app);
    }

    public function packageBooted(): void
    {
        $this->app->make(ConfigValidator::class)->validate($this->app->make(VaulterConfig::class), $this->app);
        (new HttpRegistrar)->boot($this->app);
        (new AccessRegistrar)->boot($this->app);
        (new BindingsRegistrar)->boot($this->app);
        (new SchedulingRegistrar)->register($this->app);
    }
}
