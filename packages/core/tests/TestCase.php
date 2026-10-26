<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VaulterServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());
        SuiteDatabaseConnection::isolateStorage($app);

        // The package's `vaulter-public`/`vaulter-uploads` named rate limiters
        // (P1.3 / F-P1.8-01) are backed by the Laravel cache. Testbench's own
        // default cache store is 'database', which needs a `cache` table this
        // suite's migrations never create — pin the in-memory 'array' store so a
        // throttled route never 500s on a missing table.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.routes.middleware.authenticated', []);

        // Pin the auto-increment strategy for the base suite. The published
        // config now defaults to ulid (D9); the legacy default-panel document
        // tests assert bigint keys, so the foundation suite keeps bigint and the
        // UlidTestCase exercises the ulid path explicitly.
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A test meant for another engine is skipped here: the application is booted, no schema exists yet (groups engine:*).
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        $this->loadMigrationsFrom(SuiteDatabaseConnection::migrationPaths($this->app));
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite — the fast,
     * dependency-free lane the entire suite uses locally and in the main CI matrix.
     *
     * Opt INTO Postgres by exporting DB_CONNECTION=pgsql (plus the DB_* coordinates):
     * the dedicated pgsql CI job does this to exercise the real advisory-xact-lock /
     * FOR UPDATE critical sections (race-free blob commit, destructive orphan prune)
     * that SQLite cannot model — every such test SKIPS unless the live driver is pgsql
     * (master plan P4 #11). No env, no Postgres needed: the suite stays on SQLite.
     *
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        return SuiteDatabaseConnection::resolve();
    }
}
