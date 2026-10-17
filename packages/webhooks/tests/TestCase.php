<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Tests;

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
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

/**
 * Boots the kernel, the CAS engine, the drive and the webhooks package with the opt-in webhooks module
 * switched on (D22): the webhook suites exercise the module, the default of a host (off) is the kernel's
 * business and is pinned by the core activation tests.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use ValidatesOpenApiResponses;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // No test hits real DNS or a socket: the outbound SSRF guard resolves through a fake resolver that maps
        // hosts to a public address by default, so a delivery/registration to a test host (hooks.test) passes
        // the range check. An SSRF test overrides the map via app(HostResolver::class)->set('evil.test', ['127.0.0.1']).
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
            // The drive module is what webhooks require (endpoints are scoped to drives, the events are the drive's),
            // and the drive needs the CAS engine of the media package.
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
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

        // The package's named rate limiters are backed by the Laravel cache. Testbench's own default cache store is
        // 'database', which needs a `cache` table this suite's migrations never create.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's 'api' group definition;
        // authorization is exercised through the manage ability.
        $app['config']->set('vaulter.routes.middleware.authenticated', []);

        // The drive tests the events come from run OPEN under the env-gated NullPermissionDriver (non-prod only).
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');

        // Pin the auto-increment strategy: the webhook tests assert integer keys on the drive chain they build.
        $app['config']->set('vaulter.ids.strategy', 'bigint');

        WebhookTestHelpers::enableModule($app);
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
