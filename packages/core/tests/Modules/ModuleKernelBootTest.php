<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Modules\ModuleState;
use Vaulter\Tests\Concerns\FreshApplication;
use Vaulter\Tests\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\Modules\Fixtures\FixtureModulesProvider;
use Vaulter\Tests\Modules\Fixtures\Greeter;
use Vaulter\Tests\Modules\Fixtures\PlainGreeter;
use Vaulter\Tests\Modules\Fixtures\Step;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// The core provider compiles the offered manifests in a booting callback — after every provider
// registered, before any provider boots — and seals the kernel. Each case boots a fresh application;
// the application of the running test is not touched.

final class ModulesBootProbe
{
    /** @var list<string> */
    public static array $sealedWhenBooting = [];
}

final class ModulesBootOrderProvider extends ServiceProvider
{
    public function boot(): void
    {
        ModulesBootProbe::$sealedWhenBooting[] = $this->app->make(Kernel::class)->isSealed() ? 'sealed' : 'open';
    }
}

/**
 * @param array<string, mixed> $config
 * @param list<class-string>   $before providers listed before the Vaulter providers
 * @param list<class-string>   $after  providers listed after them
 */
function bootFreshModulesApplication(array $config = [], array $before = [], array $after = []): Application
{
    return FreshApplication::boot($config, $before, $after);
}

/**
 * @param  list<ModuleState>         $modules
 * @return list<array{string, bool}>
 */
function moduleActivity(array $modules): array
{
    return array_map(static fn (ModuleState $s): array => [$s->id->value, $s->active], $modules);
}

beforeEach(function (): void {
    ModulesBootProbe::$sealedWhenBooting = [];
});

it('seals a valid graph of the offered modules for the suite application', function (): void {
    $kernel = $this->app->make(Kernel::class);

    expect($kernel->isSealed())->toBeTrue()
        ->and($kernel->isValid())->toBeTrue()
        ->and($kernel->fingerprint())->toMatch('/^[0-9a-f]{64}$/')
        ->and($kernel->isActive('core'))->toBeTrue()
        ->and($kernel->isActive('webhooks'))->toBeFalse();
});

it('compiles the offered manifests whichever side of the core provider offers them', function (string $side): void {
    $app = $side === 'before'
        ? bootFreshModulesApplication(before: [FixtureModulesProvider::class])
        : bootFreshModulesApplication(after: [FixtureModulesProvider::class]);
    $kernel = $app->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and(array_values(array_filter(moduleActivity($kernel->modules()), static fn (array $m): bool => in_array($m[0], ['host', 'optional', 'plugin'], true))))
        ->toBe([['host', true], ['optional', false], ['plugin', true]])
        ->and($kernel->capabilities()->require(Greeter::class))->toBeInstanceOf(PlainGreeter::class)
        ->and($kernel->extensions()->handlerKeys(Step::class))->toBe(['first', 'third']);
})->with(['before' => ['before'], 'after' => ['after']]);

it('compiles before any provider boots, and without a query', function (): void {
    bootFreshModulesApplication(before: [ModulesBootOrderProvider::class, FixtureModulesProvider::class]);

    expect(ModulesBootProbe::$sealedWhenBooting)->toBe(['sealed'])
        ->and(FreshApplication::$queries)->toBe(0);
});

it('applies vaulter.modules overrides', function (): void {
    $kernel = bootFreshModulesApplication(['vaulter.modules' => ['optional' => true]], after: [FixtureModulesProvider::class])->make(Kernel::class);

    $optional = array_values(array_filter($kernel->modules(), static fn (ModuleState $s): bool => $s->id->value === 'optional'))[0];

    expect($kernel->extensions()->handlerKeys(Step::class))->toBe(['first', 'second', 'third'])
        ->and($optional->activeReason)->toBe(ModuleState::REASON_OVERRIDE);
});

it('boots with an invalid graph, activates nothing and reports why', function (array $modules, string $reason): void {
    $kernel = bootFreshModulesApplication(['vaulter.modules' => $modules], after: [FixtureModulesProvider::class])->make(Kernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->modules())->toBe([])
        ->and($kernel->reasons())->toContain($reason)
        ->and(fn () => $kernel->capabilities())->toThrow(ModuleGraphException::class);
})->with([
    'unknown key' => [['nope' => true], 'vaulter.modules.nope names no installed module'],
    'string false' => [['optional' => 'false'], 'vaulter.modules.optional must be true or false, string given'],
    'dependency off' => [['host' => false], 'module "plugin" is active but requires "host", which is not active'],
]);

it('refuses a module offered after the application booted', function (): void {
    expect(fn () => $this->app->register(FixtureModulesProvider::class, force: true))
        ->toThrow(RegistryFrozenException::class);
});
