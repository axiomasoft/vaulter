<?php

declare(strict_types=1);

namespace Vaulter\Load\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Load\LoadServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Tests\Webhooks\FakeHostResolver;
use Vaulter\VaulterServiceProvider;

abstract class LoadTestCase extends OrchestraTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostResolver::class, static fn (): FakeHostResolver => new FakeHostResolver);

        $this->configureHealthyBaseline();
    }

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
            LoadServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('vaulter.middleware', []);
    }

    protected function configureHealthyBaseline(): void
    {
        $sentinel = (string) config('load.health.s3_sentinel_key', '.vaulter-health-sentinel');

        config([
            'database.default' => 'testing',
            'filesystems.disks.s3' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/s3'),
                'throw' => false,
            ],
            'vaulter.storage.default_disk' => 's3',
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'queue.connections.redis' => [
                'driver' => 'sync',
                'queue' => 'default',
            ],
        ]);

        Storage::disk('s3')->put($sentinel, 'ok');
    }
}
