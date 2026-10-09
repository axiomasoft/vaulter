<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Providers\HttpRegistrar;

final class VaulterDriveServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-drive')->hasConfigFile();

        (new HttpRegistrar)->configure($package);
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar($this->app))->register();
    }

    public function packageBooted(): void
    {
        $config = $this->app->make(DriveConfig::class);
        $config->model('upload_session');
        $config->model('upload_part');
    }
}
