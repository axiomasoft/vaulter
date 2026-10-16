<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests;

use Illuminate\Foundation\Application;

use function Orchestra\Testbench\load_migration_paths;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Media\VaulterMediaServiceProvider;
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
        return [
            // Core provides the Contracts\Cas seam, the Blob model + v_blobs schema
            // and Configuration\VaulterConfig; this package binds the concrete CAS engine on top.
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
        ];
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

        // The CAS slice is ACL-agnostic: run OPEN under the env-gated null driver so
        // the tests exercise pure blob mechanics without an access-control lane.
        $app['config']->set('vaulter.access.permission_driver', 'null');

        // Pin the auto-increment strategy to bigint — the CAS tests assert integer
        // blob keys.
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A test meant for another engine is skipped here: the application is booted, no schema exists yet (groups engine:*).
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        // The migrations of every offered module: the blob tests pin blobs for an opaque owner (D23) and need no drive table.
        $paths = SuiteDatabaseConnection::migrationPaths($this->app);
        load_migration_paths($this->app, $paths);
        $this->loadMigrationsFrom($paths);
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
        return SuiteDatabaseConnection::resolve();
    }
}
