<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Testing\FakeHostResolver;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;
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
        $this->pinSuiteConnection($app);
        $this->pinOpenSuite($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
