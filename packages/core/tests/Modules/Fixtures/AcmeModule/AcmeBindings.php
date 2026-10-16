<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Modules\ModuleBindings;

/** The container wiring of the module, instantiated only while it is active. */
final class AcmeBindings implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(AcmeService::class);
    }

    public function boot(Application $app): void {}
}
