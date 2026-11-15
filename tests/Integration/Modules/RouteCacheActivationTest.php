<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;

uses(TestCase::class, WithoutDatabaseSchema::class);

// Revision 3: a route cache is never rebuilt from the manifests (ServiceProvider::loadRoutesFrom skips a
// cached application). A module route outlives its module in a stale cache, so every module route group carries
// vaulter.module:<id> (404 for an inactive module) and the fingerprint the doctor compares.
// Writes bootstrap/cache/routes-v7.php of the Testbench skeleton: partition skeleton_writer.

/** The compiled route collection of `$app`, as `route:cache` writes it. */
function compiledRoutes(Application $app): array
{
    $routes = $app['router']->getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    return $routes->compile();
}

/** Boots with a route cache present (the providers skip their route files), then loads `$compiled` as the cache. */
function bootFromRouteCache(array $compiled, array $config): Application
{
    $path = FreshApplication::boot()->getCachedRoutesPath();
    @mkdir(dirname($path), 0o777, true);
    file_put_contents($path, "<?php\n\n// RouteCacheActivationTest: the routes are loaded by the test.\n");
    try {
        $app = FreshApplication::boot($config);
    } finally {
        @unlink($path);
    }
    $app['router']->setCompiledRoutes($compiled);

    return $app;
}

/** @return list<string> the finding codes of the core.modules.cache doctor check */
function moduleCacheCodes(Application $app): array
{
    $console = $app->make(ConsoleKernel::class);
    $console->call('vaulter:doctor', ['--check' => ['core.modules.cache'], '--json' => true]);
    $report = json_decode(trim($console->output()), true, flags: JSON_THROW_ON_ERROR);

    return array_column($report['checks'][0]['findings'], 'code');
}

it('answers 404 for a cached route of a module switched off after the cache was built', function (): void {
    $compiled = compiledRoutes(FreshApplication::boot(['vaulter.modules.webhooks' => true]));
    $app = bootFromRouteCache($compiled, ['vaulter.modules.webhooks' => false]);

    $response = $app->make(HttpKernel::class)->handle(Request::create('/api/vaulter/v1/webhook-endpoints', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));

    expect($app->routesAreCached())->toBeTrue()
        ->and($app['router']->has('vaulter.v1.webhook-endpoints.index'))->toBeTrue()
        ->and($response->getStatusCode())->toBe(404)
        ->and(moduleCacheCodes($app))->toBe(['core.modules.route_cache_stale']);
});

it('reports an active module missing from a route cache built before it was switched on', function (): void {
    $compiled = compiledRoutes(FreshApplication::boot(['vaulter.modules.webhooks' => false]));
    $app = bootFromRouteCache($compiled, ['vaulter.modules.webhooks' => true]);

    expect($app['router']->has('vaulter.v1.webhook-endpoints.index'))->toBeFalse()
        ->and(moduleCacheCodes($app))->toContain('core.modules.routes_missing');
});

it('accepts a route cache built under the running activation', function (): void {
    $compiled = compiledRoutes(FreshApplication::boot(['vaulter.modules.webhooks' => true]));
    $app = bootFromRouteCache($compiled, ['vaulter.modules.webhooks' => true]);

    expect(moduleCacheCodes($app))->toBe(['core.modules.cache_fresh']);
});
