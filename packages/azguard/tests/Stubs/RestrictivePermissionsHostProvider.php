<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Stubs;

use Illuminate\Support\ServiceProvider;

final class RestrictivePermissionsHostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('vaulter-azguard.permissions', ['edit' => 'host.docs.write']);
    }
}
