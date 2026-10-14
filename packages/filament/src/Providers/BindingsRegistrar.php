<?php

declare(strict_types=1);

namespace Vaulter\Filament\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Filament\Configuration\FilamentConfig;
use Vaulter\Modules\ModuleBindings;

/** The container wiring of the Filament module: its config reader. */
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(FilamentConfig::class, static fn (Container $app): FilamentConfig => new FilamentConfig($app->make('config')));
    }

    public function boot(Application $app): void {}
}
