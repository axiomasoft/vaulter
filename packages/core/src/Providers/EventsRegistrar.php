<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Events\EventRecorder;
use Vaulter\Modules\ModuleBindings;

final class EventsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        // One instance remembers which events it recorded, so recording one twice fails.
        $app->singleton(EventRecorder::class);
    }

    public function boot(Application $app): void {}
}
