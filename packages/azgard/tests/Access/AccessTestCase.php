<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\Access;

use Illuminate\Foundation\Application;
use Vaulter\Azgard\Tests\TestCase;
use Vaulter\Testing\Support\SuiteDatabaseConnection;

abstract class AccessTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (SuiteDatabaseConnection::migrationPaths($this->app) as $path) {
            $this->loadMigrationsFrom($path);
        }
    }
}
