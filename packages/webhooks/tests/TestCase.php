<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Testing\FakeHostResolver;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

/**
 * Boots the kernel, the CAS engine, the drive and the webhooks package with the opt-in webhooks module
 * switched on: the webhook suites exercise the module, the default of a host (off) is the kernel's
 * business and is pinned by the core activation tests.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;
    use ValidatesOpenApiResponses;

    #[Override]
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
        $this->pinSuiteConnection($app);
        $this->pinOpenSuite($app);
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');
        WebhookTestHelpers::enableModule($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
