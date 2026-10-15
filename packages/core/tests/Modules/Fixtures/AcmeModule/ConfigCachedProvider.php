<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Support\ServiceProvider;

/**
 * Makes the application behave as one booted from `config:cache`: Testbench never loads a cached configuration, so the
 * test hands the cached roots over as config and this provider sets the flag the module applier and the framework read.
 */
final class ConfigCachedProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('config_loaded_from_cache', true);
    }
}
