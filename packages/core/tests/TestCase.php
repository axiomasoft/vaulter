<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Media\VaulterMediaServiceProvider;
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
            // The CAS engine (ContentStore/StorageDriver/BlobStore) lives in the
            // vaulter-media package (P2 #5); the core Drive upload/download/copy
            // suites resolve those bindings through its provider.
            VaulterMediaServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());

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
        if (env('DB_CONNECTION') !== 'pgsql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => (string) env('DB_DATABASE', 'vaulter_test'),
            'username' => (string) env('DB_USERNAME', 'vaulter'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
