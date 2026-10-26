<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmePingController;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeProbe;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeProvider;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeService;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\ConfigCachedProvider;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSet;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSets;
use Vaulter\Integration\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// I6 with caches (D22, revision 3): `route:cache` and `config:cache` freeze what they saw. A cache built before an
// activation change must never serve a module the application no longer runs, and must never hide a module it now
// runs. The module is the third-party AcmeModule, so the cached route is a package route, not a core one.
// The route cache is written to bootstrap/cache of the Testbench skeleton: partition skeleton_writer (-j1, a TESTBENCH_APP_BASE_PATH of its own).

const ACME_PING = '/api/vaulter/v1/acme/ping';

/** @param array<string, mixed> $config */
function cacheBoot(array $config = []): Application
{
    AcmeProbe::reset();

    return FreshApplication::boot($config, after: [AcmeProvider::class]);
}

/** The compiled route collection of `$app`, as `route:cache` writes it. */
function cacheRoutesOf(Application $app): array
{
    $routes = $app['router']->getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    return $routes->compile();
}

/**
 * Boots with a route cache present (the providers skip their route files), then loads `$compiled` as the cache.
 *
 * @param array<string, mixed> $config
 */
function cacheBootFromRoutes(array $compiled, array $config): Application
{
    $path = cacheBoot()->getCachedRoutesPath();
    @mkdir(dirname($path), 0o777, true);
    file_put_contents($path, "<?php\n\n// ActivationCacheTest: the routes are loaded by the test.\n");
    try {
        $app = cacheBoot($config);
    } finally {
        @unlink($path);
    }
    $app['router']->setCompiledRoutes($compiled);

    return $app;
}

/** @return list<string> the finding codes of the core.modules.cache doctor check */
function cacheDoctorCodes(Application $app): array
{
    $console = $app->make(ConsoleKernel::class);
    $console->call('vaulter:doctor', ['--check' => ['core.modules.cache'], '--json' => true]);
    $report = json_decode(trim($console->output()), true, flags: JSON_THROW_ON_ERROR);

    return array_column($report['checks'][0]['findings'], 'code');
}

function cacheGet(Application $app, string $uri, string $method = 'GET'): int
{
    return $app->make(HttpKernel::class)->handle(Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'application/json']))->getStatusCode();
}

it('answers 404 for the cached route of a module switched off after route:cache, and builds nothing of it', function (): void {
    $compiled = cacheRoutesOf(cacheBoot(['vaulter.modules.acme' => true]));
    $app = cacheBootFromRoutes($compiled, []);
    AcmeProbe::reset();

    expect($app->routesAreCached())->toBeTrue()
        ->and($app['router']->has('vaulter.v1.acme.ping'))->toBeTrue()
        ->and(cacheGet($app, ACME_PING))->toBe(404)
        ->and(AcmeProbe::$resolved)->toBe([])
        ->and(cacheDoctorCodes($app))->toBe(['core.modules.route_cache_stale']);
});

it('reports a module switched on after route:cache as missing from the cache instead of serving nothing silently', function (): void {
    $compiled = cacheRoutesOf(cacheBoot());
    $app = cacheBootFromRoutes($compiled, ['vaulter.modules.acme' => true]);

    expect($app['router']->has('vaulter.v1.acme.ping'))->toBeFalse()
        ->and(cacheGet($app, ACME_PING))->toBe(404)
        ->and(cacheDoctorCodes($app))->toContain('core.modules.routes_missing');
});

it('serves the cached route of a module under the activation the cache was built with', function (): void {
    $compiled = cacheRoutesOf(cacheBoot(['vaulter.modules.acme' => true]));
    $app = cacheBootFromRoutes($compiled, ['vaulter.modules.acme' => true]);
    AcmeProbe::reset();

    expect(cacheGet($app, ACME_PING))->toBe(200)
        ->and(AcmeProbe::$resolved)->toBe([AcmeService::class, AcmePingController::class])
        ->and(cacheDoctorCodes($app))->toBe(['core.modules.cache_fresh']);
});

it('keeps the activation of a config cache: the same graph and fingerprint, until the cache is cleared', function (): void {
    $built = cacheBoot(['vaulter.modules.acme' => true]);
    $fingerprint = $built->make(Kernel::class)->fingerprint();
    // What config:cache would freeze of Vaulter: the merged roots, here with a value the module defaults do not carry.
    $cached = [];
    foreach (['vaulter', 'vaulter-acme'] as $root) {
        $cached[$root] = $built['config']->get($root);
    }
    $cached['vaulter-acme']['greeting'] = 'cached hello';

    AcmeProbe::reset();
    $app = FreshApplication::boot($cached, before: [ConfigCachedProvider::class], after: [AcmeProvider::class]);
    expect($app->configurationIsCached())->toBeTrue()
        ->and($app->make(Kernel::class)->isActive('acme'))->toBeTrue()
        ->and($app->make(Kernel::class)->fingerprint())->toBe($fingerprint)
        // The module defaults are not merged over a cached configuration: the frozen value stands.
        ->and($app['config']->get('vaulter-acme.greeting'))->toBe('cached hello');

    // config:clear: the host's own switch counts again, and the graph is another one.
    $cleared = cacheBoot();
    expect($cleared->configurationIsCached())->toBeFalse()
        ->and($cleared->make(Kernel::class)->isActive('acme'))->toBeFalse()
        ->and($cleared->make(Kernel::class)->fingerprint())->not->toBe($fingerprint);
});

// The same cache rules on the application sets of the install matrix (P4.18, I6): a set whose top module mounts routes,
// route:cache built while the module was active, then the module switched off.

/** @param array<string, mixed> $config */
function cacheSetBoot(AppSet $set, array $config = []): Application
{
    return FreshApplication::boot($config, case: $set->testCase());
}

/**
 * Boots the set with a route cache present, then loads `$compiled` as the cache.
 *
 * @param array<string, mixed> $config
 */
function cacheSetFromRoutes(AppSet $set, array $compiled, array $config): Application
{
    $path = cacheSetBoot($set)->getCachedRoutesPath();
    @mkdir(dirname($path), 0o777, true);
    file_put_contents($path, "<?php\n\n// ActivationCacheTest: the routes are loaded by the test.\n");
    try {
        $app = cacheSetBoot($set, $config);
    } finally {
        @unlink($path);
    }
    $app['router']->setCompiledRoutes($compiled);

    return $app;
}

/**
 * The first route of `$module`, a GET one when it has any (route parameters filled in with 1): the request that must reach the module.
 *
 * @return array{string, string} method, URI
 */
function cacheSetProbe(Application $app, string $module): array
{
    $first = null;
    foreach ($app['router']->getRoutes()->getRoutes() as $route) {
        if (!$route instanceof Route || !in_array(EnsureModuleActive::ALIAS.':'.$module, $route->middleware(), true)) {
            continue;
        }
        $method = in_array('GET', $route->methods(), true) ? 'GET' : $route->methods()[0];
        $probe = [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())];
        if ($method === 'GET') {
            return $probe;
        }
        $first ??= $probe;
    }

    return $first ?? throw new RuntimeException("No route of module $module.");
}

it('answers 404 for every cached route of the module a set adds once it is switched off after route:cache, with the cache stale in the doctor', function (AppSet $set): void {
    $built = cacheSetBoot($set, $set->on);
    [$method, $probe] = cacheSetProbe($built, $set->top);
    $compiled = cacheRoutesOf($built);

    $served = cacheSetFromRoutes($set, $compiled, $set->on);
    $off = cacheSetFromRoutes($set, $compiled, $set->off());

    expect($off->routesAreCached())->toBeTrue()
        ->and($off->make(Kernel::class)->isActive($set->top))->toBeFalse()
        ->and(cacheGet($off, $probe, $method))->toBe(404)
        // The same request reaches the module while it is active: the 404 is the switch, not a missing record.
        ->and(cacheGet($served, $probe, $method))->not->toBe(404)
        ->and(cacheDoctorCodes($off))->toBe(['core.modules.route_cache_stale']);
})->with(fn (): array => AppSets::routedDataset());

it('reports the module a set adds as missing from a route cache built before it was switched on', function (AppSet $set): void {
    $compiled = cacheRoutesOf(cacheSetBoot($set, $set->off()));
    $on = cacheSetFromRoutes($set, $compiled, $set->on);

    [$method, $probe] = cacheSetProbe(cacheSetBoot($set, $set->on), $set->top);

    expect(cacheGet($on, $probe, $method))->toBe(404)
        ->and(cacheDoctorCodes($on))->toContain('core.modules.routes_missing');
})->with(fn (): array => AppSets::routedDataset());
