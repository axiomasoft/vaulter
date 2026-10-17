<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Vaulter\Collaboration\CollaborationModule;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\Operations\CollaborationOperations;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Kernel;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleState;
use Vaulter\Testing\Concerns\FreshApplication;

/**
 * The collaboration module (D22): opt-in, so a host that installed the package and did not switch it on has no routes,
 * no driver and no operations of it; a host that did has the session and hub routes behind the module middleware,
 * the transport capability of the documents module and the problem codes, and nothing of it without its dependency.
 */

/** @return list<Route> the routes of the collaboration module */
function collaborationRoutes(Application $app): array
{
    return array_values(array_filter(
        $app['router']->getRoutes()->getRoutes(),
        static fn (Route $route): bool => in_array(EnsureModuleActive::ALIAS.':collaboration', $route->middleware(), true),
    ));
}

function collaborationState(Application $app): ModuleState
{
    return array_values(array_filter($app->make(Kernel::class)->modules(), static fn (ModuleState $state): bool => $state->id->value === 'collaboration'))[0];
}

it('declares an opt-in manifest that needs the documents module', function (): void {
    $module = new CollaborationModule;
    $builder = new ModuleBuilder($module->id());
    $module->register($builder);

    expect($module->id()->value)->toBe('collaboration')
        ->and($module->activation())->toBe(Activation::OptIn)
        ->and(array_keys($module->requires()))->toBe(['core', 'drive', 'documents'])
        ->and(array_keys($builder->configs()))->toBe(['vaulter-collaboration'])
        ->and(array_map(realpath(...), $builder->routeFiles()))->toBe([realpath(__DIR__.'/../../routes/collaboration.php')])
        ->and(array_map(static fn ($provider): string => $provider->contract.'=>'.$provider->implementation, $builder->capabilityProviders()))->toBe([CollaborationDriver::class.'=>'.HocuspocusCollaborationDriver::class]);
});

it('stays off by default: no routes, no driver, no operations, and the host still serves its own requests', function (): void {
    $app = FreshApplication::boot();
    $kernel = $app->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('collaboration'))->toBeFalse()
        ->and(collaborationState($app)->activeReason)->toBe(ModuleState::REASON_DEFAULT)
        ->and(collaborationRoutes($app))->toBe([])
        ->and($app['router']->has('vaulter.v1.collaboration.hub.store'))->toBeFalse()
        ->and($app['router']->has('vaulter.v1.documents.collaboration.session'))->toBeFalse()
        ->and($kernel->capabilities()->has(CollaborationDriver::class))->toBeFalse()
        ->and($app->bound(CollaborationOperations::class))->toBeFalse()
        ->and($app['config']->has('vaulter-collaboration'))->toBeFalse()
        ->and(FreshApplication::$queries)->toBe(0);
});

it('mounts the session and the hub routes behind the module middleware once it is switched on', function (): void {
    $app = FreshApplication::boot(['vaulter.modules.collaboration' => true]);
    $kernel = $app->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('collaboration'))->toBeTrue()
        ->and(collaborationState($app)->activeReason)->toBe(ModuleState::REASON_OVERRIDE)
        ->and(array_map(static fn (Route $route): string => (string) $route->getName(), collaborationRoutes($app)))->toEqualCanonicalizing([
            'vaulter.v1.documents.collaboration.session',
            'vaulter.v1.collaboration.hub.authenticate',
            'vaulter.v1.collaboration.hub.load',
            'vaulter.v1.collaboration.hub.store',
        ])
        ->and($kernel->capabilities()->require(CollaborationDriver::class))->toBeInstanceOf(HocuspocusCollaborationDriver::class)
        ->and($app['config']->get('vaulter-collaboration.driver'))->toBe('hocuspocus')
        ->and(FreshApplication::$queries)->toBe(0);
});

it('activates nothing at all while the documents module it needs is switched off', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['collaboration' => true, 'documents' => false]]);
    $kernel = $app->make(Kernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toContain('module "collaboration" is active but requires "documents", which is not active')
        ->and(collaborationRoutes($app))->toBe([])
        ->and($app->make(ConsoleKernel::class)->all())->toHaveKey('vaulter:modules');
});

it('keeps the problem codes of the collaboration concept out of the catalogue while the module is off', function (): void {
    $off = FreshApplication::boot()->make(ProblemCatalog::class);
    $on = FreshApplication::boot(['vaulter.modules.collaboration' => true])->make(ProblemCatalog::class);

    foreach (['collaboration_epoch_mismatch', 'collaboration_seed_conflict', 'collaboration_unavailable'] as $code) {
        expect($off->has($code))->toBeFalse($code)
            ->and($on->has($code))->toBeTrue($code)
            ->and($on->module($code))->toBe('collaboration')
            ->and($on->title($code, 'en'))->not->toBeNull()
            ->and($on->title($code, 'ru'))->not->toBeNull();
    }
});
