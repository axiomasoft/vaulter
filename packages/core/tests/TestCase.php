<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Tests\Webhooks\FakeHostResolver;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // No test hits real DNS or a socket (§2.11 / F-P1.7-01): the outbound SSRF
        // guard resolves through a fake resolver that maps hosts to a public address
        // by default, so a delivery/registration to a test host (hooks.test) passes
        // the range check. An SSRF test overrides the map via
        // app(HostResolver::class)->set('evil.test', ['127.0.0.1']).
        $this->app->singleton(HostResolver::class, static fn (): FakeHostResolver => new FakeHostResolver);
    }

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
            // Drive behaviour (StorageManager, DriveHandle factory, resumable
            // uploads, PurgeTrash/SweepUploads, drive routes, Drive-event
            // Activity/outbox subscribers) lives in vaulter-drive (P2 #5, D1). The
            // cross-cutting core suites (Activity/Webhooks feeds fed by Drive events,
            // the full-stack Feature specs) resolve it through this provider.
            VaulterDriveServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());

        // The package's `vaulter-public`/`vaulter-uploads` named rate limiters
        // (P1.3 / F-P1.8-01) are backed by the Laravel cache. Testbench's own
        // default cache store is 'database', which needs a `cache` table this
        // suite's migrations never create — pin the in-memory 'array' store so a
        // throttled route never 500s on a missing table.
        $app['config']->set('cache.default', 'array');

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
        $driver = env('DB_CONNECTION');

        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        $database = (string) env('DB_DATABASE', 'vaulter_test');

        if (!str_ends_with($database, '_test')) {
            throw new \RuntimeException('Test database must end with _test: '.$database);
        }

        if ($driver !== 'pgsql') {
            return [
                'driver' => $driver,
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '3306'),
                'database' => $database,
                'username' => (string) env('DB_USERNAME', 'vaulter'),
                'password' => (string) env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => $database,
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
