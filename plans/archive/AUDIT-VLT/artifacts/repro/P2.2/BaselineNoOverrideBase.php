<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.2 baseline — NO host config published at all (the E7.1 zero-config install
 * lane). Confirms whether drive's mergeConfigFrom contributes upload_session/
 * upload_part to vaulter.models when nothing pre-empts it.
 */
abstract class BaselineNoOverrideBase extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // NO vaulter.* override at all — clean install, nothing published.
    }
}
