<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Support\ServiceProvider;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Drive\DriveHandleFactory;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Uploads\UploadSessionManager;

final class BindingsRegistrar extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/vaulter-drive.php', 'vaulter.models');

        $this->app->bind(DriveHandleFactoryContract::class, DriveHandleFactory::class);
        $this->app->singleton(DriveProvisioner::class);
        $this->app->singleton(UploadSessionManager::class);
    }
}
