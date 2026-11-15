<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Integration\Tests\Modules\Fixtures\FixtureProblemCodes;
use Vaulter\Integration\Tests\Modules\Fixtures\MalformedProblemCodes;
use Vaulter\Integration\Tests\Modules\Fixtures\NotAGreeter;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\ModuleState;
use Vaulter\Modules\SlotContribution;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Tests\Modules\Fixtures\FirstStep;
use Vaulter\Tests\Modules\Fixtures\FixtureModule;
use Vaulter\Tests\Modules\Fixtures\Greeter;
use Vaulter\Tests\Modules\Fixtures\HostModule;
use Vaulter\Tests\Modules\Fixtures\LoudGreeter;
use Vaulter\Tests\Modules\Fixtures\OptionalModule;
use Vaulter\Tests\Modules\Fixtures\PlainGreeter;
use Vaulter\Tests\Modules\Fixtures\PluginModule;
use Vaulter\Tests\Modules\Fixtures\SecondStep;
use Vaulter\Tests\Modules\Fixtures\Step;
use Vaulter\Tests\Modules\Fixtures\ThirdStep;

// The module registry compiles deterministically; duplicates, missing capabilities, cycles and
// unknown slots fail the whole graph at compilation (knowledge/p3-modules#module-system).

/** @param list<VaulterModule> $modules */
function compileModules(array $modules, array $overrides = []): CompiledModules|InvalidModuleGraph
{
    return (new ModuleCompiler)->compile($modules, $overrides);
}

/** @param list<VaulterModule> $modules */
function invalidReasons(array $modules, array $overrides = []): array
{
    $result = compileModules($modules, $overrides);
    expect($result)->toBeInstanceOf(InvalidModuleGraph::class);

    return $result instanceof InvalidModuleGraph ? $result->reasons : [];
}

/** A host defining Greeter (RequiredSingle) and the Step slot, as fixture modules. */
function fixtureHost(?Closure $more = null, Cardinality $greeters = Cardinality::RequiredSingle, SlotKind $kind = SlotKind::Contributor): FixtureModule
{
    return new FixtureModule('host', declare: static function (ModuleBuilder $m) use ($more, $greeters, $kind): void {
        $m->defineCapability(Greeter::class, $greeters)->defineSlot(Step::class, $kind);
        if ($more !== null) {
            $more($m);
        }
    });
}

function fixturePlugin(string $id = 'plugin', ?Closure $declare = null, Activation $activation = Activation::Auto, array $requires = ['host' => '^1.0']): FixtureModule
{
    return new FixtureModule($id, $activation, $requires, $declare ?? static function (ModuleBuilder $m): void {
        $m->provides(Greeter::class, PlainGreeter::class);
    });
}

it('compiles an empty set into a valid graph with nothing active', function (): void {
    $result = compileModules([]);

    expect($result)->toBeInstanceOf(CompiledModules::class)
        ->and($result->modules)->toBe([])
        ->and($result->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
});

it('compiles the fixture manifests: states, providers and ordered contributions', function (): void {
    $result = compileModules([new OptionalModule, new PluginModule, new HostModule], ['optional' => true]);

    expect($result)->toBeInstanceOf(CompiledModules::class);
    assert($result instanceof CompiledModules);
    expect(array_map(static fn (ModuleState $s): array => [$s->id->value, $s->active, $s->activeReason], $result->modules))->toBe([
        ['host', true, ModuleState::REASON_DEFAULT],
        ['optional', true, ModuleState::REASON_OVERRIDE],
        ['plugin', true, ModuleState::REASON_DEFAULT],
    ])
        ->and($result->providers[Greeter::class][0]->implementation)->toBe(PlainGreeter::class)
        ->and(array_map(static fn (SlotContribution $c): string => $c->id, $result->contributions[Step::class]))
        ->toBe(['plugin.first', 'optional.second', 'host.third'])
        ->and($result->schemaPaths)->toHaveCount(2);
});

it('rejects a duplicate module id', function (): void {
    expect(invalidReasons([new FixtureModule('host'), new FixtureModule('host')]))
        ->toContain(sprintf('module id "host" is declared by both %s and %s', FixtureModule::class, FixtureModule::class));
});

it('rejects a malformed module id and protocol', function (string $id, string $version, string $reason): void {
    expect(implode("\n", invalidReasons([new FixtureModule($id, version: $version)])))->toContain($reason);
})->with([
    'upper case id' => ['Host', '1.0', 'is not a module id'],
    'one character id' => ['h', '1.0', 'is not a module id'],
    'semver protocol' => ['host', '1.0.0', 'declares the protocol "1.0.0", expected MAJOR.MINOR'],
    'bare major protocol' => ['host', '1', 'declares the protocol "1", expected MAJOR.MINOR'],
]);

it('rejects an override for a module that is not installed', function (): void {
    expect(invalidReasons([new FixtureModule('host')], ['webhooks' => true]))
        ->toBe(['vaulter.modules.webhooks names no installed module']);
});

it('rejects an override that is not strictly boolean', function (mixed $value, string $type): void {
    expect(invalidReasons([new FixtureModule('host')], ['host' => $value]))
        ->toBe([sprintf('vaulter.modules.host must be true or false, %s given', $type)]);
})->with([
    'the string false' => ['false', 'string'],
    'one' => [1, 'int'],
    'null' => [null, 'null'],
]);

it('rejects an overrides value that is not an array', function (): void {
    $result = (new ModuleCompiler)->compileOffered([], 'webhooks');

    expect($result)->toBeInstanceOf(InvalidModuleGraph::class)
        ->and($result->reasons)->toBe(['vaulter.modules must be an array of module id => bool']);
});

it('rejects an offered class that is not a manifest', function (): void {
    $result = (new ModuleCompiler)->compileOffered([PlainGreeter::class], []);

    expect($result)->toBeInstanceOf(InvalidModuleGraph::class)
        ->and($result->reasons[0])->toContain('does not implement '.VaulterModule::class);
});

it('activates by policy and lets an override win either way', function (?bool $override, Activation $activation, bool $active, string $reason): void {
    $overrides = $override === null ? [] : ['mod' => $override];
    $result = compileModules([new FixtureModule('mod', $activation)], $overrides);

    expect($result)->toBeInstanceOf(CompiledModules::class);
    assert($result instanceof CompiledModules);
    expect($result->module('mod')?->active)->toBe($active)
        ->and($result->module('mod')?->activeReason)->toBe($reason);
})->with([
    'auto by default' => [null, Activation::Auto, true, ModuleState::REASON_DEFAULT],
    'opt-in by default' => [null, Activation::OptIn, false, ModuleState::REASON_DEFAULT],
    'auto switched off' => [false, Activation::Auto, false, ModuleState::REASON_OVERRIDE],
    'opt-in switched on' => [true, Activation::OptIn, true, ModuleState::REASON_OVERRIDE],
    'auto confirmed' => [true, Activation::Auto, true, ModuleState::REASON_OVERRIDE],
    'opt-in confirmed off' => [false, Activation::OptIn, false, ModuleState::REASON_OVERRIDE],
]);

it('rejects an active module whose dependency is inactive, and never enables the dependency', function (): void {
    expect(invalidReasons([fixtureHost(), fixturePlugin()], ['host' => false]))
        ->toContain('module "plugin" is active but requires "host", which is not active');
});

it('accepts an inactive module whose dependency is inactive', function (): void {
    $result = compileModules([fixtureHost(), fixturePlugin()], ['host' => false, 'plugin' => false]);

    expect($result)->toBeInstanceOf(CompiledModules::class);
});

it('rejects a dependency that is not installed', function (): void {
    expect(invalidReasons([new FixtureModule('plugin', requires: ['host' => '^1.0'])]))
        ->toBe(['module "plugin" requires "host", which is not installed']);
});

it('checks the protocol against ^MAJOR.MINOR only', function (string $installed, string $constraint, ?string $reason): void {
    $modules = [new FixtureModule('host', version: $installed), new FixtureModule('plugin', requires: ['host' => $constraint])];
    $result = compileModules($modules);

    if ($reason === null) {
        expect($result)->toBeInstanceOf(CompiledModules::class);

        return;
    }
    expect($result)->toBeInstanceOf(InvalidModuleGraph::class)
        ->and(implode("\n", $result instanceof InvalidModuleGraph ? $result->reasons : []))->toContain($reason);
})->with([
    'same minor' => ['1.0', '^1.0', null],
    'newer minor' => ['1.3', '^1.2', null],
    'older minor' => ['1.1', '^1.2', 'requires "host" ^1.2, installed protocol is 1.1'],
    'other major' => ['2.0', '^1.0', 'requires "host" ^1.0, installed protocol is 2.0'],
    'tilde form' => ['1.0', '~1.0', 'unsupported constraint "~1.0"'],
    'range form' => ['1.0', '>=1.0', 'unsupported constraint ">=1.0"'],
    'patch form' => ['1.0', '^1.0.0', 'unsupported constraint "^1.0.0"'],
]);

it('rejects a requires cycle among active modules', function (): void {
    $reasons = invalidReasons([
        new FixtureModule('alpha', requires: ['beta' => '^1.0']),
        new FixtureModule('beta', requires: ['gamma' => '^1.0']),
        new FixtureModule('gamma', requires: ['alpha' => '^1.0']),
    ]);

    expect($reasons)->toBe(['the requires graph has a cycle: alpha -> beta -> gamma -> alpha']);
});

it('requires exactly one provider of a required single capability', function (int $providers): void {
    $modules = [fixtureHost()];
    foreach (array_slice(['plugin', 'other'], 0, $providers) as $id) {
        $modules[] = fixturePlugin($id);
    }

    expect(invalidReasons($modules))
        ->toContain(sprintf('capability %s (required-single) has %d active providers', Greeter::class, $providers));
})->with(['none' => [0], 'two' => [2]]);

it('takes at most one provider of an optional single capability', function (): void {
    $modules = [fixtureHost(greeters: Cardinality::OptionalSingle), fixturePlugin('plugin'), fixturePlugin('other')];

    expect(invalidReasons($modules))
        ->toContain(sprintf('capability %s (optional-single) has 2 active providers', Greeter::class));
    expect(compileModules([fixtureHost(greeters: Cardinality::OptionalSingle)]))->toBeInstanceOf(CompiledModules::class);
});

it('counts only active providers', function (): void {
    $result = compileModules([fixtureHost(), fixturePlugin('plugin'), fixturePlugin('other', activation: Activation::OptIn)]);

    expect($result)->toBeInstanceOf(CompiledModules::class);
});

it('rejects a provider of an undefined capability', function (): void {
    expect(invalidReasons([new FixtureModule('host', declare: static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class))]))
        ->toBe([sprintf('module "host" provides %s, which no module defines', Greeter::class)]);
});

it('rejects a capability defined twice', function (): void {
    $twice = new FixtureModule('twice', declare: static fn (ModuleBuilder $m) => $m->defineCapability(Greeter::class, Cardinality::Many));

    expect(invalidReasons([fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)), $twice]))
        ->toContain(sprintf('capability %s is defined by both "host" and "twice"', Greeter::class));
});

it('rejects a provider that does not require the defining module', function (): void {
    expect(invalidReasons([fixtureHost(), fixturePlugin(requires: [])]))
        ->toContain(sprintf('module "plugin" provides %s but does not require "host", which defines it', Greeter::class));
});

it('rejects an implementation that does not implement the contract', function (): void {
    $plugin = fixturePlugin(declare: static fn (ModuleBuilder $m) => $m->provides(Greeter::class, NotAGreeter::class));

    expect(invalidReasons([fixtureHost(), $plugin]))
        ->toContain(sprintf('module "plugin" provides %s with %s, which does not implement it', Greeter::class, NotAGreeter::class));
});

it('counts a config-chosen provider and validates its default', function (): void {
    $fromConfig = fixturePlugin(declare: static fn (ModuleBuilder $m) => $m->providesFromConfig(Greeter::class, 'acme.greeter', LoudGreeter::class));
    $badDefault = fixturePlugin(declare: static fn (ModuleBuilder $m) => $m->providesFromConfig(Greeter::class, 'acme.greeter', NotAGreeter::class));

    expect(compileModules([fixtureHost(), $fromConfig]))->toBeInstanceOf(CompiledModules::class)
        ->and(invalidReasons([fixtureHost(), $badDefault]))
        ->toContain(sprintf('module "plugin" provides %s with %s, which does not implement it', Greeter::class, NotAGreeter::class));
});

it('rejects a contribution to an unknown slot', function (): void {
    $module = new FixtureModule('plugin', declare: static fn (ModuleBuilder $m) => $m->slot(Step::class, FirstStep::class, 'plugin.first'));

    expect(invalidReasons([$module]))
        ->toBe([sprintf('module "plugin" contributes "plugin.first" to the unknown slot %s', Step::class)]);
});

it('rejects a slot defined twice', function (): void {
    $twice = new FixtureModule('twice', declare: static fn (ModuleBuilder $m) => $m->defineSlot(Step::class, SlotKind::Guard));

    expect(invalidReasons([fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)), $twice]))
        ->toContain(sprintf('slot %s is defined by both "host" and "twice"', Step::class));
});

it('rejects a contributor that does not require the slot owner', function (): void {
    $plugin = fixturePlugin(requires: [], declare: static fn (ModuleBuilder $m) => $m->slot(Step::class, FirstStep::class, 'plugin.first'));
    $host = fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class));

    expect(invalidReasons([$host, $plugin]))
        ->toContain(sprintf('module "plugin" contributes "plugin.first" to %s but does not require "host", which defines it', Step::class));
});

it('rejects a contribution that does not implement the slot contract', function (): void {
    $plugin = fixturePlugin(declare: static function (ModuleBuilder $m): void {
        $m->provides(Greeter::class, PlainGreeter::class)->slot(Step::class, PlainGreeter::class, 'plugin.wrong');
    });

    expect(invalidReasons([fixtureHost(), $plugin]))
        ->toContain(sprintf('contribution "plugin.wrong" to %s is %s, which does not implement it', Step::class, PlainGreeter::class));
});

it('rejects a duplicate contribution id in a slot', function (): void {
    $plugin = fixturePlugin(declare: static function (ModuleBuilder $m): void {
        $m->provides(Greeter::class, PlainGreeter::class)
            ->slot(Step::class, FirstStep::class, 'same')
            ->slot(Step::class, SecondStep::class, 'same', priority: 5);
    });

    expect(invalidReasons([fixtureHost(), $plugin]))
        ->toContain(sprintf('slot %s has the contribution id "same" more than once', Step::class));
});

it('rejects a duplicate or missing handler key', function (): void {
    $duplicate = fixturePlugin(declare: static function (ModuleBuilder $m): void {
        $m->provides(Greeter::class, PlainGreeter::class)
            ->slot(Step::class, FirstStep::class, 'plugin.a', key: 'pdf')
            ->slot(Step::class, SecondStep::class, 'plugin.b', key: 'pdf');
    });
    $missing = fixturePlugin(declare: static function (ModuleBuilder $m): void {
        $m->provides(Greeter::class, PlainGreeter::class)->slot(Step::class, FirstStep::class, 'plugin.a');
    });

    expect(invalidReasons([fixtureHost(kind: SlotKind::Handler), $duplicate]))
        ->toContain(sprintf('slot %s has the key "pdf" more than once', Step::class))
        ->and(invalidReasons([fixtureHost(kind: SlotKind::Handler), $missing]))
        ->toContain(sprintf('contribution "plugin.a" to the handler slot %s has no key', Step::class));
});

it('rejects a name owned by two modules', function (): void {
    $config = static fn (ModuleBuilder $m) => $m->config('acme', '/tmp/acme.php');

    expect(invalidReasons([new FixtureModule('one', declare: $config), new FixtureModule('two', declare: $config)]))
        ->toBe(['config root "acme" is declared by both "one" and "two"']);
});

it('reports a manifest that fails while declaring', function (): void {
    $broken = new FixtureModule('broken', declare: static fn (ModuleBuilder $m) => $m->routes(''));

    expect(invalidReasons([$broken]))->toBe(['module "broken" failed to declare itself: [Vaulter] Module broken declares an empty routes file.']);
});

it('collects every reason and activates nothing', function (): void {
    $reasons = invalidReasons([
        new FixtureModule('host'),
        new FixtureModule('host'),
        new FixtureModule('plugin', requires: ['missing' => '^1.0']),
    ], ['ghost' => true]);

    expect($reasons)->toHaveCount(3);
});

it('orders contributions by priority, then id, whatever the order of the offers', function (): void {
    $modules = static fn (): array => [
        fixtureHost(),
        fixturePlugin('plugin', static function (ModuleBuilder $m): void {
            $m->provides(Greeter::class, PlainGreeter::class)
                ->slot(Step::class, SecondStep::class, 'plugin.b', priority: 10)
                ->slot(Step::class, FirstStep::class, 'plugin.a', priority: 10);
        }),
        fixturePlugin('extra', static fn (ModuleBuilder $m) => $m->slot(Step::class, ThirdStep::class, 'extra.early', priority: -5)),
    ];
    $order = static function (array $modules): array {
        $result = compileModules($modules);
        assert($result instanceof CompiledModules);

        return [array_map(static fn (SlotContribution $c): string => $c->id, $result->contributions[Step::class]), $result->fingerprint];
    };

    $forward = $order($modules());
    $reversed = $order(array_reverse($modules()));
    $rotated = $modules();
    $rotated = [$rotated[1], $rotated[2], $rotated[0]];

    expect($forward[0])->toBe(['extra.early', 'plugin.a', 'plugin.b'])
        ->and($reversed)->toBe($forward)
        ->and($order($rotated))->toBe($forward);
});

it('keeps the fingerprint stable across offers and changes it with the active set', function (): void {
    $offered = [new OptionalModule, new PluginModule, new HostModule];
    $base = compileModules($offered);
    $shuffled = compileModules([new HostModule, new OptionalModule, new PluginModule]);
    $optionalOn = compileModules($offered, ['optional' => true]);
    $sameActiveViaOverride = compileModules($offered, ['plugin' => true]);

    assert($base instanceof CompiledModules && $shuffled instanceof CompiledModules && $optionalOn instanceof CompiledModules && $sameActiveViaOverride instanceof CompiledModules);
    expect($shuffled->fingerprint)->toBe($base->fingerprint)
        ->and($optionalOn->fingerprint)->not->toBe($base->fingerprint)
        ->and($sameActiveViaOverride->fingerprint)->not->toBe($base->fingerprint);
});

it('compiles without a container or facades', function (): void {
    $previous = Container::getInstance();
    Container::setInstance(new class extends Container
    {
        public function make($abstract, array $parameters = []): mixed
        {
            throw new LogicException('the compiler resolved '.(is_string($abstract) ? $abstract : 'a closure'));
        }
    });

    try {
        $result = (new ModuleCompiler)->compileOffered([HostModule::class, PluginModule::class, OptionalModule::class], ['optional' => true]);
    } finally {
        Container::setInstance($previous);
    }

    expect($result)->toBeInstanceOf(CompiledModules::class);
});

it('only takes class-strings: a closure is a type error', function (): void {
    $builder = new ModuleBuilder(new ModuleId('host'));

    expect(fn () => $builder->provides(Greeter::class, static fn (): PlainGreeter => new PlainGreeter))->toThrow(TypeError::class);
});

it('refuses a problem code declared by two installed modules', function (): void {
    $reasons = invalidReasons([
        fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)->problems(FixtureProblemCodes::class)),
        fixturePlugin('optional', static fn (ModuleBuilder $m) => $m->problems(FixtureProblemCodes::class), Activation::OptIn, requires: []),
    ]);

    expect($reasons)->toContain('problem code "fixture_conflict" is declared by both "host" and "optional"')
        ->and($reasons)->toContain('problem code "fixture_missing" is declared by both "host" and "optional"');
});

it('refuses a problem code a module declares twice through two sources', function (): void {
    $reasons = invalidReasons([
        fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)->problems(FixtureProblemCodes::class)->problems(FixtureProblemCodes::class)),
    ]);

    expect($reasons)->toContain(sprintf('module "host" declares the problem code "fixture_conflict" twice (%s)', FixtureProblemCodes::class));
});

it('refuses a problem source that is not one, a malformed code and an entry without a spec', function (): void {
    $reasons = invalidReasons([
        fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)
            ->problems(MalformedProblemCodes::class)
            ->problems(PlainGreeter::class)),
    ]);

    expect($reasons)->toContain(sprintf('module "host" declares the problem source %s, which does not implement %s', PlainGreeter::class, ProblemCodeSource::class))
        ->and($reasons)->toContain(sprintf('problem source %s declares the malformed code "Not-A-Code"', MalformedProblemCodes::class))
        ->and($reasons)->toContain(sprintf('problem source %s declares "fixture_raw" without a %s', MalformedProblemCodes::class, ProblemSpec::class));
});

it('keeps the problem sources of an inactive module out of the sealed state', function (): void {
    $result = compileModules([
        fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)),
        fixturePlugin('optional', static fn (ModuleBuilder $m) => $m->problems(FixtureProblemCodes::class), Activation::OptIn, requires: []),
    ]);
    $enabled = compileModules([
        fixtureHost(static fn (ModuleBuilder $m) => $m->provides(Greeter::class, PlainGreeter::class)),
        fixturePlugin('optional', static fn (ModuleBuilder $m) => $m->problems(FixtureProblemCodes::class), Activation::OptIn, requires: []),
    ], ['optional' => true]);

    assert($result instanceof CompiledModules && $enabled instanceof CompiledModules);
    expect($result->problems)->toBe([])
        ->and($enabled->problems)->toBe(['optional' => [FixtureProblemCodes::class]]);
});
