<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Testing\FakeHostResolver;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use ValidatesOpenApiResponses;

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
            // The opt-in webhooks module (a dev dependency of the kernel tests): the activation, outbox and
            // module-graph suites use it as the module with an external consumer; it stays off unless a test
            // switches vaulter.modules.webhooks on.
            VaulterWebhooksServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->validateOpenApiResponses($app);

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

        // The base suite runs OPEN: the env-gated NullPermissionDriver (non-prod only,
        // H.2 / D6″) grants every actor full access, so the M1c Drive tests keep
        // their open behaviour. The M2a ACL tests opt INTO the 'database' driver
        // explicitly to exercise real grant resolution.
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');

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
