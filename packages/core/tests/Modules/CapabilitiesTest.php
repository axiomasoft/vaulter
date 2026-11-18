<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Vaulter\Modules\Capabilities;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\MissingExtensionException;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Tests\Modules\Fixtures\FixtureModule;
use Vaulter\Tests\Modules\Fixtures\Greeter;
use Vaulter\Tests\Modules\Fixtures\LoudGreeter;
use Vaulter\Tests\Modules\Fixtures\PlainGreeter;

// Capabilities read the sealed registry only (ADR-0027): defined, provided by an active module, or a
// host config key naming the class.

/** @param array<string, mixed> $config */
function capabilitiesOf(Cardinality $cardinality, ?Closure $provide, array $config = [], array $overrides = []): Capabilities
{
    $modules = [new FixtureModule('host', declare: static fn (ModuleBuilder $m): ModuleBuilder => $m->defineCapability(Greeter::class, $cardinality))];
    if ($provide !== null) {
        $modules[] = new FixtureModule('plugin', requires: ['host' => '^1.0'], declare: $provide);
    }
    $compiled = (new ModuleCompiler)->compile($modules, $overrides);
    assert($compiled instanceof CompiledModules);

    $container = new Container;
    $container->instance(RepositoryContract::class, new Repository($config));
    // What ModuleApplier::provide binds for a single capability: the contract is one container singleton.
    if ($cardinality !== Cardinality::Many) {
        $container->singleton(Greeter::class, static function (Container $c) use ($compiled): object {
            $provider = $compiled->providers[Greeter::class][0];
            $configured = $provider->configKey === null ? null : $c->make(RepositoryContract::class)->get($provider->configKey);
            $class = is_string($configured) && $configured !== '' ? $configured : ($provider->implementation ?? $provider->default);

            return $c->make((string) $class);
        });
    }

    return new Capabilities($compiled, $container);
}

it('resolves the single provider of a capability', function (): void {
    $capabilities = capabilitiesOf(Cardinality::RequiredSingle, static fn (ModuleBuilder $m): ModuleBuilder => $m->provides(Greeter::class, PlainGreeter::class));

    expect($capabilities->has(Greeter::class))->toBeTrue()
        ->and($capabilities->require(Greeter::class))->toBeInstanceOf(PlainGreeter::class);
});

it('has no capability that no active module defines', function (): void {
    $capabilities = capabilitiesOf(Cardinality::OptionalSingle, null);

    expect($capabilities->has(Greeter::class))->toBeFalse()
        ->and($capabilities->has(LoudGreeter::class))->toBeFalse()
        ->and(fn (): object => $capabilities->require(Greeter::class))->toThrow(MissingExtensionException::class);
});

it('drops the provider of an inactive module', function (): void {
    $capabilities = capabilitiesOf(
        Cardinality::OptionalSingle,
        static fn (ModuleBuilder $m): ModuleBuilder => $m->provides(Greeter::class, PlainGreeter::class),
        overrides: ['plugin' => false],
    );

    expect($capabilities->has(Greeter::class))->toBeFalse();
});

it('reads a config-chosen provider when resolved, falling back to its default', function (): void {
    $provide = static fn (ModuleBuilder $m): ModuleBuilder => $m->providesFromConfig(Greeter::class, 'acme.greeter');
    $withDefault = static fn (ModuleBuilder $m): ModuleBuilder => $m->providesFromConfig(Greeter::class, 'acme.greeter', PlainGreeter::class);

    expect(capabilitiesOf(Cardinality::OptionalSingle, $provide)->has(Greeter::class))->toBeFalse()
        ->and(capabilitiesOf(Cardinality::OptionalSingle, $provide, ['acme' => ['greeter' => '']])->has(Greeter::class))->toBeFalse()
        ->and(capabilitiesOf(Cardinality::OptionalSingle, $provide, ['acme' => ['greeter' => LoudGreeter::class]])->require(Greeter::class))
        ->toBeInstanceOf(LoudGreeter::class)
        ->and(capabilitiesOf(Cardinality::OptionalSingle, $withDefault)->require(Greeter::class))->toBeInstanceOf(PlainGreeter::class)
        ->and(capabilitiesOf(Cardinality::OptionalSingle, $withDefault, ['acme' => ['greeter' => LoudGreeter::class]])->require(Greeter::class))
        ->toBeInstanceOf(LoudGreeter::class);
});

it('resolves every provider of a many capability and refuses require()', function (): void {
    $compiled = (new ModuleCompiler)->compile([
        new FixtureModule('host', declare: static fn (ModuleBuilder $m): ModuleBuilder => $m->defineCapability(Greeter::class, Cardinality::Many)),
        new FixtureModule('first', requires: ['host' => '^1.0'], declare: static fn (ModuleBuilder $m): ModuleBuilder => $m->provides(Greeter::class, PlainGreeter::class)),
        new FixtureModule('second', requires: ['host' => '^1.0'], declare: static fn (ModuleBuilder $m): ModuleBuilder => $m->provides(Greeter::class, LoudGreeter::class)),
    ], []);
    expect($compiled)->toBeInstanceOf(CompiledModules::class);
    $container = new Container;
    $container->instance(RepositoryContract::class, new Repository);
    $capabilities = new Capabilities($compiled, $container);

    expect(array_map(static fn (Greeter $g): string => $g->greet(), $capabilities->all(Greeter::class)))->toBe(['hello', 'HELLO'])
        ->and(fn (): object => $capabilities->require(Greeter::class))->toThrow(MissingExtensionException::class, 'takes many providers');
});
