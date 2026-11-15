<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VaulterServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->pinSuiteConnection($app);

        // The package's `vaulter-public`/`vaulter-uploads` named rate limiters
        // are backed by the Laravel cache. Testbench's own
        // default cache store is 'database', which needs a `cache` table this
        // suite's migrations never create — pin the in-memory 'array' store so a
        // throttled route never 500s on a missing table.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.routes.middleware.authenticated', []);

        // Pin the auto-increment strategy for the base suite. The published
        // config now defaults to ulid; the legacy default-panel document
        // tests assert bigint keys, so the foundation suite keeps bigint and the
        // UlidTestCase exercises the ulid path explicitly.
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
