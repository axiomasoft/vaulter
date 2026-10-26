<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Route;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleState;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Integration\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// D39: the drive module declares its own slices and those still located in core. Switched off, none of
// them is registered — not the core-located routes, bindings or commands either (I6).

it('registers the drive commands only while the drive is active', function (): void {
    $active = FreshApplication::boot()->make(ConsoleKernel::class)->all();
    $inactive = FreshApplication::boot(['vaulter.modules' => ['drive' => false]])->make(ConsoleKernel::class)->all();

    foreach (['vaulter:trash:purge', 'vaulter:scan:reconcile', 'vaulter:changes:prune', 'vaulter:make:profile'] as $command) {
        expect($active)->toHaveKey($command)
            ->and($inactive)->not->toHaveKey($command);
    }
});

it('mounts no drive route, binds no drive service and still boots without a query', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['drive' => false]]);
    $kernel = $app->make(Kernel::class);
    $drive = array_values(array_filter(
        $app['router']->getRoutes()->getRoutes(),
        static fn (Route $route): bool => in_array(EnsureModuleActive::ALIAS.':drive', $route->middleware(), true),
    ));

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('drive'))->toBeFalse()
        ->and($kernel->isActive('media'))->toBeTrue()
        ->and($drive)->toBe([])
        ->and($app['router']->has('vaulter.v1.grants.index'))->toBeFalse()
        ->and($app['router']->has('vaulter.v1.public-shares.show'))->toBeFalse()
        ->and($app->bound(DriveHandleFactory::class))->toBeFalse()
        ->and($app->bound('vaulter'))->toBeFalse()
        ->and(FreshApplication::$queries)->toBe(0);
});

it('lists the drive as inactive by override', function (): void {
    $kernel = FreshApplication::boot(['vaulter.modules' => ['drive' => false]])->make(Kernel::class);
    $drive = array_values(array_filter($kernel->modules(), static fn (ModuleState $s): bool => $s->id->value === 'drive'))[0];

    expect($drive->active)->toBeFalse()
        ->and($drive->activeReason)->toBe(ModuleState::REASON_OVERRIDE);
});

it('mounts the core-located drive routes behind vaulter.module:drive while the drive is active', function (): void {
    $route = $this->app['router']->getRoutes()->getByName('vaulter.v1.grants.index');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain(EnsureModuleActive::ALIAS.':drive');
});

it('refuses a graph whose active modules require the switched-off drive', function (array $config, array $after, string $reason): void {
    $kernel = FreshApplication::boot($config, after: $after)->make(Kernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toContain($reason);
})->with([
    'documents' => [['vaulter.modules' => ['drive' => false]], [VaulterDocumentsServiceProvider::class], 'module "documents" is active but requires "drive", which is not active'],
    'webhooks' => [['vaulter.modules' => ['drive' => false, 'webhooks' => true]], [], 'module "webhooks" is active but requires "drive", which is not active'],
    'media off' => [['vaulter.modules' => ['media' => false]], [], 'module "drive" is active but requires "media", which is not active'],
]);
