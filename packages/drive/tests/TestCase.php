<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
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
            // Core owns the schema (v_nodes/v_storages/v_files/... + v_upload_*),
            // the Node/Storage models, Access/Sharing/Activity and Support\Config.
            VaulterServiceProvider::class,
            // Media binds the concrete CAS engine behind the Contracts\Cas seam —
            // Drive upload/download/versioning commit through it.
            VaulterMediaServiceProvider::class,
            // This package binds the Drive behaviour: StorageManager, the DriveHandle
            // factory, the resumable-upload lifecycle, the PurgeTrash/SweepUploads
            // commands, the drive routes and the Drive-event Activity/outbox subscribers.
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

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.middleware', []);

        // The Drive suite runs OPEN under the env-gated NullRoleDriver (non-prod
        // only, H.2 / D6″): every actor gets full access so the tests exercise pure
        // file/folder/upload mechanics without an access-control lane.
        $app['config']->set('vaulter.drivers.permission', 'null');

        // Pin the auto-increment strategy to bigint — the Drive tests assert integer
        // keys on the Storage→Node→File→Version chain they build.
        $app['config']->set('vaulter.ids.default', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The whole v_* schema (incl. v_upload_sessions/v_upload_parts) is owned by
        // core; load its migrations through the path-repo symlink Composer places in
        // this package's vendor dir.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/axioma-studio/vaulter-core/database/migrations');
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite. Opt INTO
     * Postgres by exporting DB_CONNECTION=pgsql (plus the DB_* coordinates): the
     * dedicated pgsql CI lane exercises the real advisory-xact-lock / FOR UPDATE /
     * SERIALIZABLE critical sections behind the race-free upload-commit that SQLite
     * cannot model — every such test SKIPS unless the live driver is pgsql.
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
