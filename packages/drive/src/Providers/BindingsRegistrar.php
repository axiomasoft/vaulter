<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\DriveHandleFactory;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Uploads\UploadSessionManager;

final class BindingsRegistrar extends ServiceProvider
{
    public function register(): void
    {
        $path = __DIR__.'/../../config/vaulter-drive.php';
        $this->mergeConfigFrom(path: $path, key: 'vaulter-drive');
        $this->app->singleton(DriveConfig::class, fn (Container $app): DriveConfig => new DriveConfig($app->make('config')));
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-drive', path: $path);

        $this->app->bind(DriveHandleFactoryContract::class, DriveHandleFactory::class);
        $this->app->singleton(DriveProvisioner::class);
        $this->app->singleton(UploadSessionManager::class);
    }
}
