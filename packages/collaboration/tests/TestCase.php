<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

/**
 * Boots the kernel, the CAS engine, the drive, the documents and the collaboration package. The collaboration module
 * is opt-in (D22) and stays off here, as it does in a host that did not switch it on; the tests that exercise the
 * module use {@see Collaboration\CollaborationEnabledTestCase}.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use ValidatesOpenApiResponses;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            // The drive module is what documents require, and the drive needs the CAS engine of the media package.
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
            VaulterCollaborationServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        if ((string) env('VAULTER_OPENAPI_VALIDATE') === '1') {
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(\Vaulter\Collaboration\Tests\Support\ValidateReleaseOpenApiResponses::class);
        }

        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());
        SuiteDatabaseConnection::isolateStorage($app);

        // The package's named rate limiters are backed by the Laravel cache. Testbench's own default cache store is
        // 'database', which needs a `cache` table this suite's migrations never create.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's 'api' group definition;
        // authorization is exercised via the resolver.
        $app['config']->set('vaulter.routes.middleware.authenticated', []);

        // The collaboration suite runs OPEN under the env-gated NullPermissionDriver (non-prod only): every actor
        // gets full access, so the tests exercise the collaboration mechanics without an access-control lane.
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');

        // Pin the auto-increment strategy: the tests assert integer keys on the drive chain they build.
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A test meant for another engine is skipped here: the application is booted, no schema exists yet (groups engine:*).
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        $this->loadMigrationsFrom(SuiteDatabaseConnection::migrationPaths($this->app));
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite. Opt INTO Postgres/MySQL by exporting
     * DB_CONNECTION (plus the DB_* coordinates): the server-engine lanes exercise the row locks and the
     * transactions SQLite cannot model.
     *
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        return SuiteDatabaseConnection::resolve();
    }
}
