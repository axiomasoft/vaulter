<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Vaulter\AzGuard\VaulterAzGuardServiceProvider;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Filament\VaulterFilamentServiceProvider;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeAudit\AcmeFacetedAuditServiceProvider;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeCommand;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeListener;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeMiddleware;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmePingController;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmePinged;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeProbe;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeProvider;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeService;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSet;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSets;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\VetoDriveProvider;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\ModuleState;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

uses(TestCase::class, WithoutDatabaseSchema::class);

// The activation matrix (architecture#module-system): an absent, installed-inactive, active,
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
    // Installed plugins retain declarative config, diagnostics, problem definitions and schema metadata.
    // These registrations are lazy; the runtime surface below must remain unchanged while inactive.
    $bindings = array_values(array_filter(array_keys($app->getBindings()), static function (string $binding): bool {
        foreach ([OptionsFactory::class, DoctorCheck::class, SchemaSetProvider::class] as $metadata) {
            if (is_a($binding, $metadata, true)) {
                return false;
            }
        }
        $provider = str_contains($binding, ':') ? substr($binding, strpos($binding, ':') + 1) : '';

        return !is_a($provider, ProblemDefinitionProvider::class, true);
    }));
    $roots = array_values(array_filter(array_keys($app['config']->all()), static fn (string $root): bool => $root === 'vaulter' || (str_starts_with($root, 'vaulter-') && $app->make(Kernel::class)->isActive(substr($root, 8)))));
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

/** The zero price of a module that is not active, as the spies see it. */
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

it('limits the Vaulter activation policy to the modules offered by this application', function (): void {
    $app = matrixApp();
    $gate = ExtensionRegistrar::activationGateFor($app);
    expect($gate)->not->toBeNull()
        ->and($gate?->isEnabled('chatom.core'))->toBeTrue()
        ->and($gate?->isEnabled('vaulter.core'))->toBeTrue();
});

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
    $config = ['vaulter-drive.access.permission_driver' => 'no-such-driver'];

    expect(fn () => FreshApplication::boot($config))
        ->toThrow(InvalidConfigurationException::class, 'Unknown vaulter-drive.access.permission_driver "no-such-driver"');

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

// The install matrix: the same price rules on the downward-closed application sets of the package graph — core;
// +media; +drive; +documents; +webhooks; +azguard; +filament. Each set is a fresh application with exactly its providers
// (Fixtures/Apps). route:cache and config:cache before an activation change: ActivationCacheTest.

/** @param array<string, mixed> $config */
function setApp(AppSet $set, array $config = []): Application
{
    return FreshApplication::boot($config, case: $set->testCase());
}

/** @return list<string> the ids of the modules the application offers, sorted */
function setOffered(Application $app): array
{
    $ids = array_map(static fn (ModuleState $state): string => $state->id->value, $app->make(Kernel::class)->modules());
    sort($ids);

    return $ids;
}

/** @return list<string> the ids of the modules that are active, sorted */
function setActive(Application $app): array
{
    $ids = array_values(array_map(
        static fn (ModuleState $state): string => $state->id->value,
        array_filter($app->make(Kernel::class)->modules(), static fn (ModuleState $state): bool => $state->active),
    ));
    sort($ids);

    return $ids;
}

it('boots a set with exactly the modules of its packages active, mounts routes only for those that have them, and runs no query', function (AppSet $set): void {
    $app = setApp($set, $set->on);
    $kernel = $app->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and(setOffered($app))->toBe($set->sortedModules())
        ->and(setActive($app))->toBe($set->sortedModules())
        ->and(FreshApplication::$queries)->toBe(0);

    foreach (AppSets::ALL_MODULES as $module) {
        $mounts = in_array($module, $set->modules, true) && in_array($module, AppSets::ROUTE_MODULES, true);
        expect(matrixRoutes($app, $module) !== [])->toBe($mounts, "routes of module $module in set {$set->id}");
    }
})->with(fn (): array => AppSets::dataset());

it('keeps an OptIn module off in a set until its switch is on, and the Auto modules on without one', function (): void {
    $set = AppSets::get('+webhooks');
    $app = setApp($set);
    $state = array_values(array_filter($app->make(Kernel::class)->modules(), static fn (ModuleState $s): bool => $s->id->value === 'webhooks'))[0];

    expect($app->make(Kernel::class)->isValid())->toBeTrue()
        ->and($state->active)->toBeFalse()
        ->and($state->activeReason)->toBe(ModuleState::REASON_DEFAULT)
        ->and(setActive($app))->toBe(['core', 'drive', 'media'])
        ->and(matrixRoutes($app, 'webhooks'))->toBe([])
        ->and(matrixSurface($app))->toBe(matrixSurface(setApp(AppSets::get('core+media+drive'))))
        ->and(FreshApplication::$queries)->toBe(0);
});

it('costs nothing for the module a set adds when it is switched off: the application shows what its parent set shows', function (AppSet $set): void {
    $off = setApp($set, $set->off());
    $parent = setApp(AppSets::get((string) $set->parent));
    $kernel = $off->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive($set->top))->toBeFalse()
        ->and(setOffered($off))->toBe($set->sortedModules())
        ->and(matrixRoutes($off, $set->top))->toBe([])
        ->and(FreshApplication::$queries)->toBe(0);
    expect(matrixSurface($off))->toBe(matrixSurface($parent))
        ->and(matrixForeignRequest($off))->toBe(200)
        ->and(FreshApplication::$queries)->toBe(0);
})->with(fn (): array => AppSets::childrenDataset());

it('activates nothing at all while a module the top of the set requires is switched off, and keeps the diagnostics', function (AppSet $set): void {
    $app = setApp($set, [...$set->on, 'vaulter.modules' => [...($set->on['vaulter.modules'] ?? []), $set->requiredOff => false, $set->top => true]]);
    $kernel = $app->make(Kernel::class);
    $console = $app->make(ConsoleKernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toContain(sprintf('module "%s" is active but requires "%s", which is not active', $set->top, $set->requiredOff))
        ->and($kernel->modules())->toBe([])
        ->and($kernel->isActive('core'))->toBeFalse()
        ->and(matrixRoutes($app, $set->top))->toBe([])
        ->and($console->all())->not->toHaveKeys(['vaulter:install', 'vaulter:relay'])
        ->and($console->all())->toHaveKeys(['vaulter:modules', 'vaulter:doctor']);

    $response = $app->make(HttpKernel::class)->handle(Request::create('/api/vaulter/v1/nodes/n1/grants', server: ['HTTP_ACCEPT' => 'application/json']));
    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode((string) $response->getContent(), true))->toMatchArray(['code' => 'module_graph_invalid']);

    expect($console->call('vaulter:modules'))->toBe(1)
        ->and($console->output())->toContain(sprintf('module "%s" is active but requires "%s", which is not active', $set->top, $set->requiredOff));
})->with(fn (): array => AppSets::dependentDataset());

it('executes imperative ExtensionPlugin::register() middleware from a third-party dotted plugin alongside ExtensionRegistrar', function (): void {
    AcmeProbe::reset();
    AcmeMiddleware::$mode = 'pass';

    $app = FreshApplication::boot(
        after: [AcmeFacetedAuditServiceProvider::class],
    );

    $kernel = $app->make(Kernel::class);
    $inspector = $app->make(PluginInspector::class);

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('acme-audit'))->toBeTrue()
        ->and(array_map(static fn ($plugin): string => $plugin->id, $inspector->describe()))->toContain('vaulter.core', 'acme.audit');

    $middlewares = $kernel->extensions()->resolve(OperationMiddleware::class);
    expect(array_map(static fn (object $m): string => $m::class, $middlewares))->toContain(AcmeMiddleware::class);
});

it('keeps vaulter-media and optional package translation namespaces isolated when all providers boot together', function (): void {
    $app = FreshApplication::boot(
        after: [
            VaulterCollaborationServiceProvider::class,
            VaulterWebhooksServiceProvider::class,
            VaulterAzGuardServiceProvider::class,
            VaulterFilamentServiceProvider::class,
        ],
    );

    $translator = $app->make('translator');

    expect($translator->get('vaulter-media::problems.blob_unavailable.title'))->not->toBe('vaulter-media::problems.blob_unavailable.title')
        ->and($translator->get('vaulter-collaboration::problems.collaboration_draining.title'))->toBe('Collaboration draining')
        ->and($translator->get('vaulter-webhooks::problems.invalid_webhook_signature.title'))->toBe('Invalid webhook signature');
});

it('applies a host veto to the domain graph and keeps installed schema under host control', function (bool $useGate): void {
    $config = [
        'vaulter.modules.drive' => true,
        'vaulter-drive.access.permission_driver' => 'invalid-when-active',
        'packman.schema.auto_load_migrations' => false,
    ];
    if (!$useGate) {
        $config['packman.disabled_plugins'] = ['vaulter.drive'];
    }
    $app = FreshApplication::boot($config, after: $useGate ? [VetoDriveProvider::class] : []);

    expect($app->make(Kernel::class)->isValid())->toBeTrue()
        ->and($app->make(Kernel::class)->isActive('drive'))->toBeFalse()
        ->and($app->make(PluginInspector::class)->disabledIds())->toContain('vaulter.drive')
        ->and(matrixRoutes($app, 'drive'))->toBe([])
        ->and($app->make(ConsoleKernel::class)->all())->not->toHaveKey('vaulter:trash:purge')
        ->and($app['config']->has('vaulter-drive.handlers'))->toBeFalse()
        ->and($app->make('migrator')->paths())->not->toContain(realpath(__DIR__.'/../../../packages/drive/database/migrations'))
        ->and($app->make(Kernel::class)->compiled()->module('drive')?->schemaPaths)->not->toBeEmpty();
})->with([false, true]);

it('repeats a discovered provider without reopening its catalog or remounting its runtime', function (bool $active): void {
    $app = matrixApp(['vaulter.modules.drive' => $active, 'vaulter.modules.media' => $active]);
    $kernel = $app->make(Kernel::class);
    $before = matrixSurface($app);

    foreach ([VaulterServiceProvider::class, VaulterMediaServiceProvider::class] as $provider) {
        $first = $app->getProvider($provider);
        expect($app->register($provider))->toBe($first);
        $app->register($provider, force: true);
    }

    expect($app->make(Kernel::class))->toBe($kernel)
        ->and($kernel->isActive('media'))->toBe($active)
        ->and(matrixSurface($app))->toBe($before);
})->with([true, false]);

it('boots the replacement provider once when the host repeats registration before boot', function (): void {
    $first = matrixApp();
    $repeat = new class($first) extends ServiceProvider
    {
        public function register(): void
        {
            $this->app->register(VaulterServiceProvider::class, force: true);
            $this->app->register(VaulterMediaServiceProvider::class, force: true);
        }
    };
    $second = FreshApplication::boot(after: [AcmeProvider::class, $repeat::class]);

    expect($second->make(Kernel::class)->isActive('media'))->toBeTrue()
        ->and(matrixSurface($second))->toBe(matrixSurface($first));
});
