<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Vaulter\Attributes\Internal;
use Vaulter\Testing\Support\SuiteDatabaseConnection;

/**
 * The suite connection and the open-suite pins that each package TestCase repeated.
 * Provider lists, OpenAPI middleware and a package's own overrides stay in that case.
 *
 * @phpstan-require-extends TestCase
 */
#[Internal]
trait UsesSuiteDatabase
{
    /**
     * Point the suite at the testing connection and isolate its storage disk.
     */
    protected function pinSuiteConnection(Application $app): void
    {
        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());
        SuiteDatabaseConnection::isolateStorage($app);
    }

    /**
     * Shared cache, auth middleware and id defaults. Domain settings belong to
     * the package TestCase that consumes this helper.
     */
    protected function pinOpenSuite(Application $app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('vaulter.routes.middleware.authenticated', []);
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function migrateSuiteDatabase(): void
    {
        SuiteDatabaseConnection::skipOnForeignEngine($this);
        $this->loadMigrationsFrom(SuiteDatabaseConnection::migrationPaths($this->app));
    }

    /**
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        return SuiteDatabaseConnection::resolve();
    }
}
