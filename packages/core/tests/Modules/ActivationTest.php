<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\CoreModule;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Drive\DriveModule;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Tests\Concerns\FreshApplication;
use Vaulter\Tests\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\Modules\Fixtures\FixtureModulesProvider;
use Vaulter\Tests\TestCase;
use Vaulter\Webhooks\WebhooksModule;

uses(TestCase::class, WithoutDatabaseSchema::class);

// D22: the core module is Auto, the logical webhooks module (D39) is opt-in. An inactive module mounts no
// route, registers no command, contributes no doctor check; its schema is still installed.

/** @return list<Route> the routes of the module `$id` */
function routesOfModule(Application $app, string $id): array
{
    return array_values(array_filter(
        $app['router']->getRoutes()->getRoutes(),
        static fn (Route $route): bool => in_array(EnsureModuleActive::ALIAS.':'.$id, $route->middleware(), true),
    ));
}

function sealedKernel(Application $app, array $overrides): Kernel
{
    $kernel = new Kernel($app);
    $kernel->seal((new ModuleCompiler)->compile([new CoreModule, new MediaModule, new DriveModule, new WebhooksModule], $overrides));

    return $kernel;
}

it('keeps the opt-in webhooks module off by default: no route, command or doctor check', function (): void {
    $kernel = $this->app->make(Kernel::class);

    expect($kernel->isActive('core'))->toBeTrue()
        ->and($kernel->isActive('webhooks'))->toBeFalse()
        ->and(routesOfModule($this->app, 'webhooks'))->toBe([])
        ->and($this->app['router']->has('vaulter.v1.webhook-endpoints.index'))->toBeFalse()
        ->and(Artisan::all())->not->toHaveKey('vaulter:webhooks:retry')
        ->and(Artisan::all())->toHaveKey('vaulter:relay')
        ->and(Artisan::all())->toHaveKeys(['vaulter:modules', 'vaulter:module:uninstall', 'vaulter:doctor'])
        ->and(app(DoctorRegistry::class)->unmatched(['core.webhooks', 'core.modules.cache']))->toBe(['core.webhooks']);
});

it('installs the schema of every offered module, active or not', function (): void {
    $app = FreshApplication::boot(after: [FixtureModulesProvider::class]);
    $paths = $app->make('migrator')->paths();

    expect($app->make(Kernel::class)->isActive('optional'))->toBeFalse()
        ->and($paths)->toContain(dirname(__DIR__, 2).'/database/migrations')
        ->and($paths)->toContain(__DIR__.'/Fixtures/migrations/optional');
});

it('activates the webhooks module with vaulter.modules.webhooks and stamps its routes with the fingerprint', function (): void {
    $app = FreshApplication::boot(['vaulter.modules.webhooks' => true]);
    $kernel = $app->make(Kernel::class);
    $routes = routesOfModule($app, 'webhooks');

    expect($kernel->isActive('webhooks'))->toBeTrue()
        ->and(count($routes))->toBe(9)
        ->and($app[ConsoleKernel::class]->all())->toHaveKeys(['vaulter:webhooks:retry', 'vaulter:webhooks:prune', 'vaulter:webhooks:redeliver'])
        ->and($app->make(DoctorRegistry::class)->unmatched(['core.webhooks']))->toBe([]);
    foreach ($routes as $route) {
        expect($route->defaults[ModuleApplier::FINGERPRINT_DEFAULT] ?? null)->toBe($kernel->fingerprint());
    }
});

it('boots without a query whatever the activation', function (array $config): void {
    FreshApplication::boot($config);

    expect(FreshApplication::$queries)->toBe(0);
})->with([
    'defaults' => [[]],
    'webhooks on' => [['vaulter.modules.webhooks' => true]],
    'webhooks off' => [['vaulter.modules.webhooks' => false]],
]);

it('refuses the removed vaulter.features.webhooks switch at boot', function (): void {
    expect(fn () => FreshApplication::boot(['vaulter.features.webhooks' => true]))
        ->toThrow(InvalidConfigurationException::class, 'vaulter.modules.webhooks');
});

it('answers a module route of an inactive module 404 and of an invalid graph 503, from the sealed kernel only', function (): void {
    $request = Request::create('/api/vaulter/v1/webhook-endpoints');
    $next = static fn (): Response => new Response('ok');

    expect(fn () => (new EnsureModuleActive(sealedKernel($this->app, [])))->handle($request, $next, 'webhooks'))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => (new EnsureModuleActive(sealedKernel($this->app, ['nope' => true])))->handle($request, $next, 'webhooks'))
        ->toThrow(ModuleGraphException::class)
        ->and((new EnsureModuleActive(sealedKernel($this->app, ['webhooks' => true])))->handle($request, $next, 'webhooks')->getContent())
        ->toBe('ok');
});

it('hides the fingerprint default from the route action', function (): void {
    $route = (new Route(['GET'], 'x/{id}', static fn (): string => 'ok'))->defaults(ModuleApplier::FINGERPRINT_DEFAULT, 'fp');
    $request = Request::create('/x/1');
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    (new EnsureModuleActive(sealedKernel($this->app, ['webhooks' => true])))
        ->handle($request, static fn (): Response => new Response('ok'), 'webhooks');

    expect($route->parameters())->toBe(['id' => '1']);
});

it('reports a fresh module route set in the doctor', function (): void {
    $this->artisan('vaulter:doctor', ['--check' => ['core.modules.cache'], '--json' => true])
        ->expectsOutputToContain('core.modules.cache_fresh')
        ->assertSuccessful();
});

it('checks the module before auth and throttle: an inactive module answers 404 to a guest', function (): void {
    $priority = $this->app->make(Illuminate\Contracts\Http\Kernel::class)->getMiddlewarePriority();
    $position = static fn (string $class): int|false => array_search($class, $priority, true);

    expect($position(EnsureModuleActive::class))->toBeInt()
        ->and($position(AuthenticatesRequests::class))->toBeInt()
        ->and($position(EnsureModuleActive::class))->toBeLessThan($position(AuthenticatesRequests::class))
        ->and($position(EnsureModuleActive::class))->toBeLessThan($position(ThrottleRequests::class));
});
