<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.middleware', []);

        // The base suite runs OPEN: the env-gated NullRoleDriver (non-prod only,
        // H.2 / D6″) grants every actor full access, so the M1c Drive tests keep
        // their open behaviour. The M2a ACL tests opt INTO the 'database' driver
        // explicitly to exercise real grant resolution.
        $app['config']->set('vaulter.drivers.permission', 'null');

        // Pin the auto-increment strategy for the base suite. The published
        // config now defaults to ulid (D9); the legacy default-panel document
        // tests assert bigint keys, so the foundation suite keeps bigint and the
        // UlidTestCase exercises the ulid path explicitly.
        $app['config']->set('vaulter.ids.default', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
