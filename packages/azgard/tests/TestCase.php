<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaulter\Azgard\VaulterAzgardServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The base case for the bridge suite. It boots vaulter-core (the real
 * AccessResolver lives there) and this package's driver and provider
 * (the AzgardGuard binding), with the AzGuard permission driver selected — so a test
 * exercises the REAL Vaulter authorization path against the REAL adapter, with only the
 * host's User standing in as a controllable {@see AzgardBackedUser}.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application        $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterAzgardServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter.access.permission_driver', 'azgard');
        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
    }
}
