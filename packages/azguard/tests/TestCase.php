<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaulter\AzGuard\VaulterAzGuardServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The base case for the bridge suite. It boots vaulter-core (the real
 * AccessResolver lives there) and this package's driver and provider
 * (the AzGuardGuard binding), with the AzGuard permission driver selected — so a test
 * exercises the REAL Vaulter authorization path against the REAL adapter, with only the
 * host's User standing in as a controllable {@see AzGuardBackedUser}.
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
            // AzGuardModule requires the drive module (D22, D39): the access it extends — permission drivers,
            // the operation guard, the AccessResolver wiring — is declared by DriveModule, which requires media.
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterAzGuardServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter-drive.access.permission_driver', 'azguard');
        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
    }
}
