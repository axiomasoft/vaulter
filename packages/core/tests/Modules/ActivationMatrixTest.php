<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeCommand;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeListener;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmePingController;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmePinged;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeProbe;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeProvider;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeService;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// I6, the activation matrix (D22, architecture#module-system): an absent, installed-inactive, active,
// misconfigured, dependency-inactive and re-enabled module, each on a fresh application. The price of a module
// that is not active is proven with spies — queries run, objects the container built, routes, listeners and
// commands registered — not with a route list alone. The subject is the third-party AcmeModule (opt-in) and, for
// an Auto module, the drive. Caches built before an activation change: ActivationCacheTest.

/**
 * @param array<string, mixed> $config
 */
function matrixApp(array $config = [], bool $installed = true): Application
{
    AcmeProbe::reset();

    return FreshApplication::boot($config, after: $installed ? [AcmeProvider::class] : []);
}

/** @return list<Route> the routes of `$module` */
function matrixRoutes(Application $app, string $module): array
{
    return array_values(array_filter(
        $app['router']->getRoutes()->getRoutes(),
        static fn (Route $route): bool => in_array(EnsureModuleActive::ALIAS.':'.$module, $route->middleware(), true),
    ));
}

/**
 * What an application shows of its modules to the outside: the commands, the routes, the number of listeners per event,
 * the container bindings and the config roots of Vaulter. An installed-inactive module changes none of it.
 *
 * @return array<string, array<int|string, int|string>>
 */
function matrixSurface(Application $app): array
{
    $routes = array_map(static fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri(), $app['router']->getRoutes()->getRoutes());
    $commands = array_keys($app->make(ConsoleKernel::class)->all());
    $listeners = array_map('count', $app['events']->getRawListeners());
    $bindings = array_keys($app->getBindings());
    $roots = array_values(array_filter(array_keys($app['config']->all()), static fn (string $root): bool => str_starts_with($root, 'vaulter')));
    sort($routes);
    sort($commands);
    sort($bindings);
    sort($roots);
    ksort($listeners);

    return ['routes' => $routes, 'commands' => $commands, 'listeners' => $listeners, 'bindings' => $bindings, 'config' => $roots];
}

/** A request the host answers itself, to prove that a request outside the module wakes nothing. */
function matrixForeignRequest(Application $app): int
{
    $app['router']->get('host/ping', static fn (): string => 'ok');

    return $app->make(HttpKernel::class)->handle(Request::create('/host/ping'))->getStatusCode();
}

/**
 * Runs `$callback` with `$app` as the container and facade root: a migration reaches its schema builder through the `Schema`
 * facade, which a fresh application does not own (it hands the globals back to the application of the running test).
 *
 * @param Closure(): void $callback
 */
function matrixAs(Application $app, Closure $callback): void
{
    $current = Container::getInstance();
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    try {
        $callback();
    } finally {
        Container::setInstance($current);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($current);
    }
}

/** @return array{exit: int, findings: list<string>} */
function matrixDoctor(Application $app, string $check): array
{
    $console = $app->make(ConsoleKernel::class);
    $exit = $console->call('vaulter:doctor', ['--check' => [$check], '--json' => true]);
    $report = json_decode(trim($console->output()), true, flags: JSON_THROW_ON_ERROR);

    return ['exit' => $exit, 'findings' => array_merge(...array_map(static fn (array $c): array => array_column($c['findings'], 'code'), $report['checks']))];
}

/** The zero price of a module that is not active (I6), as the spies see it. */
function matrixAssertZeroCost(Application $app, string $module = 'acme'): void
{
    $kernel = $app->make(Kernel::class);

    expect($kernel->isActive($module))->toBeFalse()
        ->and(matrixRoutes($app, $module))->toBe([])
        ->and($app['router']->has('vaulter.v1.acme.ping'))->toBeFalse()
        ->and($app['events']->hasListeners(AcmePinged::class))->toBeFalse()
        ->and($app->make(ConsoleKernel::class)->all())->not->toHaveKey('acme:ping')
        ->and($app->bound(AcmeService::class))->toBeFalse()
        ->and($app['config']->has('vaulter-acme'))->toBeFalse()
        ->and(FreshApplication::$queries)->toBe(0)
        ->and(AcmeProbe::$resolved)->toBe([])
        ->and(array_filter(FreshApplication::$resolved, static fn (string $class): bool => str_contains($class, 'Fixtures\\AcmeModule\\')))->toBe([]);
}

it('costs nothing when the module is absent: it is not installed, so a switch for it is a mistake', function (): void {
    $app = matrixApp(installed: false);

    expect($app->make(Kernel::class)->isValid())->toBeTrue()
        ->and(array_map(static fn ($state): string => $state->id->value, $app->make(Kernel::class)->modules()))->not->toContain('acme')
        ->and($app->make('migrator')->paths())->not->toContain(dirname(__DIR__).'/Modules/Fixtures/AcmeModule/migrations');
    matrixAssertZeroCost($app);

    foreach ([true, false] as $switch) {
        expect(matrixApp(['vaulter.modules.acme' => $switch], installed: false)->make(Kernel::class)->reasons())
            ->toContain('vaulter.modules.acme names no installed module');
    }
});

it('costs nothing when the module is installed but inactive, yet its schema stays installed', function (): void {
    $absentApp = matrixApp(installed: false);
    $absentResolved = FreshApplication::$resolved;
    $absent = matrixSurface($absentApp);
    $app = matrixApp();
    $installedResolved = FreshApplication::$resolved;
    $installed = matrixSurface($app);

    matrixAssertZeroCost($app);
    expect($app->make(Kernel::class)->isValid())->toBeTrue()
        ->and($app->make('migrator')->paths())->toContain(__DIR__.'/Fixtures/AcmeModule/migrations')
        ->and(matrixForeignRequest($app))->toBe(200)
        ->and(FreshApplication::$queries)->toBe(0)
        ->and(AcmeProbe::$resolved)->toBe([])
        ->and($installed)->toBe($absent)
        // The container built exactly the same objects while booting with the module installed as without it.
        ->and($installedResolved)->toBe($absentResolved);
});

it('wakes nothing of an inactive module on any entry the host uses', function (): void {
    $app = matrixApp();
    $console = $app->make(ConsoleKernel::class);

    // The diagnostics see the module as inactive; they do not build a service of it.
    $console->call('vaulter:modules', ['--json' => true]);
    $modules = json_decode(trim($console->output()), true, flags: JSON_THROW_ON_ERROR);
    $acme = array_values(array_filter($modules['modules'], static fn (array $m): bool => $m['id'] === 'acme'))[0];

    expect($acme['active'])->toBeFalse()
        ->and($app->make(DoctorRegistry::class)->unmatched(['acme.endpoint']))->toBe(['acme.endpoint'])
        ->and($app->make('events')->dispatch(new AcmePinged))->toBe([])
        ->and(AcmeProbe::$resolved)->toBe([])
        ->and(AcmeProbe::$log)->toBe([]);
});

it('costs nothing for an Auto module switched off by override, and refuses what depends on it', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['drive' => false]]);
    $kernel = $app->make(Kernel::class);

    expect($kernel->isActive('drive'))->toBeFalse()
        ->and(matrixRoutes($app, 'drive'))->toBe([])
        ->and($app->make(ConsoleKernel::class)->all())->not->toHaveKey('vaulter:trash:purge')
        ->and(matrixForeignRequest($app))->toBe(200)
        ->and(FreshApplication::$queries)->toBe(0);
});

it('mounts an active module with its route behind the module middleware, its command, listener and bindings', function (): void {
    $app = matrixApp(['vaulter.modules.acme' => true]);
    $kernel = $app->make(Kernel::class);
    $routes = matrixRoutes($app, 'acme');

    expect($kernel->isActive('acme'))->toBeTrue()
        ->and(count($routes))->toBe(1)
        ->and($routes[0]->defaults[ModuleApplier::FINGERPRINT_DEFAULT] ?? null)->toBe($kernel->fingerprint())
        ->and($app->make(ConsoleKernel::class)->all())->toHaveKey('acme:ping')
        ->and($app['events']->hasListeners(AcmePinged::class))->toBeTrue()
        ->and($app->bound(AcmeService::class))->toBeTrue()
        ->and($app['config']->get('vaulter-acme.greeting'))->toBe('pong')
        ->and(FreshApplication::$queries)->toBe(0)
        // Boot builds nothing but what Artisan constructs for a command; the services stay lazy.
        ->and(AcmeProbe::$resolved)->toBe([AcmeCommand::class]);
});

it('builds the services of an active module only when something asks for them', function (): void {
    $app = matrixApp(['vaulter.modules.acme' => true]);
    AcmeProbe::reset();

    $response = $app->make(HttpKernel::class)->handle(Request::create('/api/vaulter/v1/acme/ping', server: ['HTTP_ACCEPT' => 'application/json']));
    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['greeting' => 'pong'])
        ->and(AcmeProbe::$resolved)->toBe([AcmeService::class, AcmePingController::class]);

    AcmeProbe::reset();
    $app['events']->dispatch(new AcmePinged('test'));
    expect(AcmeProbe::$resolved)->toBe([AcmeListener::class])
        ->and(AcmeProbe::$log)->toBe(['listener:test']);
});

it('refuses a misconfigured active module at boot and boots the same configuration while it is off', function (): void {
    $config = ['vaulter.access.permission_driver' => 'no-such-driver'];

    expect(fn () => FreshApplication::boot($config))
        ->toThrow(InvalidConfigurationException::class, 'Unknown vaulter.access.permission_driver "no-such-driver"');

    $app = FreshApplication::boot([...$config, 'vaulter.modules' => ['drive' => false]]);

    expect($app->make(Kernel::class)->isValid())->toBeTrue()
        ->and($app->make(Kernel::class)->isActive('drive'))->toBeFalse()
        ->and(FreshApplication::$queries)->toBe(0);
});

it('reports a misconfigured active module in the doctor, and a module that is not ready on its first operation', function (): void {
    $app = matrixApp(['vaulter.modules.acme' => true]);

    expect(matrixDoctor($app, 'acme'))->toBe(['exit' => 1, 'findings' => ['acme.endpoint_missing']]);

    $configured = matrixApp(['vaulter.modules.acme' => true, 'vaulter-acme.endpoint' => 'https://acme.test']);
    expect(matrixDoctor($configured, 'acme'))->toBe(['exit' => 0, 'findings' => ['acme.endpoint_set']]);

    // Ready is checked lazily: boot touched no table, the first operation finds the schema missing (no migration ran here).
    expect(FreshApplication::$queries)->toBe(0);
    $readiness = $app->make(ModuleReadiness::class);
    expect(fn () => $readiness->assertReady(new ModuleId('acme')))->toThrow(ModuleNotReadyException::class);

    $inactive = matrixApp();
    $queries = FreshApplication::$queries;
    expect(fn () => $inactive->make(ModuleReadiness::class)->assertReady(new ModuleId('acme')))->toThrow(ModuleNotReadyException::class, 'not active')
        ->and(FreshApplication::$queries)->toBe($queries);
});

it('activates nothing at all when an active module needs an inactive one', function (): void {
    $app = matrixApp(['vaulter.modules' => ['acme' => true, 'drive' => false]]);
    $kernel = $app->make(Kernel::class);
    $console = $app->make(ConsoleKernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toBe(['module "acme" is active but requires "drive", which is not active'])
        ->and($kernel->modules())->toBe([])
        ->and($kernel->isActive('core'))->toBeFalse()
        ->and(fn () => $kernel->assertValid())->toThrow(ModuleGraphException::class)
        ->and(matrixRoutes($app, 'acme'))->toBe([])
        ->and($app['events']->hasListeners(AcmePinged::class))->toBeFalse()
        ->and($app->bound(AcmeService::class))->toBeFalse()
        ->and($console->all())->not->toHaveKeys(['acme:ping', 'vaulter:install', 'vaulter:relay'])
        ->and($console->all())->toHaveKeys(['vaulter:modules', 'vaulter:doctor'])
        ->and(AcmeProbe::$resolved)->toBe([]);

    foreach (['/api/vaulter/v1/acme/ping', '/api/vaulter/v1/nodes/n1/grants'] as $uri) {
        $response = $app->make(HttpKernel::class)->handle(Request::create($uri, server: ['HTTP_ACCEPT' => 'application/json']));
        expect($response->getStatusCode())->toBe(503)
            ->and(json_decode((string) $response->getContent(), true))->toMatchArray(['code' => 'module_graph_invalid']);
    }
});

it('prints why the graph is invalid from the diagnostics while the other Vaulter commands exit 1', function (): void {
    $app = matrixApp(['vaulter.modules' => ['acme' => true, 'drive' => false]]);
    $console = $app->make(ConsoleKernel::class);

    $exit = $console->call('vaulter:modules');
    $output = $console->output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('module "acme" is active but requires "drive", which is not active');
    expect($console->call('vaulter:doctor'))->toBe(1)
        ->and($console->output())->toContain('module_graph_invalid');
});

it('keeps the data of a module while it is off and serves it again when the module is switched back on', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'acme-matrix-');
    $database = ['database.connections.testing.database' => $file];

    try {
        $first = matrixApp(['vaulter.modules.acme' => true, ...$database]);
        matrixAs($first, static function () use ($first): void {
            $console = $first->make(ConsoleKernel::class);
            $exit = $console->call('migrate', ['--path' => __DIR__.'/Fixtures/AcmeModule/migrations', '--realpath' => true, '--force' => true]);
            expect($exit)->toBe(0, $console->output());
        });
        $first->make(AcmeService::class)->add('kept');

        $off = matrixApp($database);
        matrixAssertZeroCost($off);
        // The table and its row are still there while the module is off: switching off is not an uninstall.
        expect($off['db']->connection()->table('acme_notes')->pluck('body')->all())->toBe(['kept']);

        $again = matrixApp(['vaulter.modules.acme' => true, ...$database]);
        expect($again->make(Kernel::class)->isActive('acme'))->toBeTrue()
            ->and($again->make(AcmeService::class)->notes())->toBe(['kept']);
    } finally {
        @unlink($file);
    }
});
