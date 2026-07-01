<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests;

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
            // Core provides the Contracts\Cas seam, the Blob model + v_blobs schema
            // and Support\Config; this package binds the concrete CAS engine on top.
            VaulterServiceProvider::class,
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

        // The CAS slice is ACL-agnostic: run OPEN under the env-gated null driver so
        // the tests exercise pure blob mechanics without an access-control lane.
        $app['config']->set('vaulter.drivers.permission', 'null');

        // Pin the auto-increment strategy to bigint — the CAS tests assert integer
        // keys on the Storage→Node→File→Version chain they build.
        $app['config']->set('vaulter.ids.default', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The v_blobs (+ Drive) schema is owned by core; load its migrations through
        // the path-repo symlink that Composer places in this package's vendor dir.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/axioma-studio/vaulter-core/database/migrations');
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite. Opt INTO
     * Postgres by exporting DB_CONNECTION=pgsql (plus the DB_* coordinates): the
     * dedicated pgsql CI lane exercises the real advisory-xact-lock / FOR UPDATE /
     * SERIALIZABLE critical sections that SQLite cannot model — every such test
     * SKIPS unless the live driver is pgsql (master plan P4 #11).
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
