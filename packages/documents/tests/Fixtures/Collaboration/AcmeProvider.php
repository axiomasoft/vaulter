<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\ModuleCatalog;

final class AcmeProvider extends ServiceProvider
{
    public function register(): void
    {
        ModuleCatalog::of($this->app)->offer(AcmeModule::class);
        $this->app->singleton(AcmeDriver::class);
    }
}
