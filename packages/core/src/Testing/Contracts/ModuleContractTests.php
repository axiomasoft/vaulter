<?php

declare(strict_types=1);

namespace Vaulter\Testing\Contracts;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
use Vaulter\CoreModule;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Operations\OperationPolicySource;

/**
 * Reusable assertions for the manifest of a third-party module, to run from a test of the Laravel application
 * that has the module's package (and the packages it requires) installed.
 *
 * A manifest is declarations only: calling `register()` runs no query, builds no new object in the container,
 * binds nothing, registers no listener and changes no config value; it makes the same declarations every time,
 * and the graph of the core module, the modules the manifest requires and the manifest compiles, active or
 * switched off; once the builder is sealed every kind of declaration is refused. Reading an object the
 * container already holds, the file system, the network and the clock cannot be observed from here: the
 * builder offers no way to them (no container, no closures), and a manifest that takes one anyway is a review finding.
 * What a manifest declares is not run here: its slot contributions are tested with the contract tests of
 * the slot that defines them.
 */
#[Api]
final class ModuleContractTests
{
    /**
     * @param list<VaulterModule> $requires the manifests the module requires, other than the core module
     */
    public function assertModuleContract(VaulterModule $module, array $requires = []): void
    {
        $id = $module->id()->value;

        $this->assertIdentity($module);
        $this->assertDeclarationsOnly($module);
        $this->assertCompiles($module, $requires);
        $this->assertSealed($module);

        Assert::assertSame($id, $module->id()->value, 'The module id changed between calls.');
    }

    private function assertIdentity(VaulterModule $module): void
    {
        $id = $module->id()->value;

        Assert::assertMatchesRegularExpression('/^\d+\.\d+$/', $module->version(), sprintf('Module "%s" version must be MAJOR.MINOR.', $id));
        foreach ($module->requires() as $dependency => $constraint) {
            Assert::assertMatchesRegularExpression('/^\^\d+\.\d+$/', $constraint, sprintf('Module "%s" must require "%s" as ^MAJOR.MINOR.', $id, $dependency));
        }
        Assert::assertSame($module->version(), $module->version(), 'The module version must not change between calls.');
        Assert::assertSame($module->activation(), $module->activation(), 'The module activation must not change between calls.');
        Assert::assertSame($module->requires(), $module->requires(), 'The module requirements must not change between calls.');
    }

    private function assertDeclarationsOnly(VaulterModule $module): void
    {
        $watch = new class
        {
            public bool $on = true;

            public int $queries = 0;

            /** @var list<string> */
            public array $resolved = [];
        };
        App::make('events')->listen(QueryExecuted::class, static function () use ($watch): void {
            $watch->queries += $watch->on ? 1 : 0;
        });
        App::resolving(static function (mixed $object) use ($watch): void {
            if ($watch->on) {
                $watch->resolved[] = get_debug_type($object);
            }
        });
        $before = $this->footprint();

        try {
            $first = new ModuleBuilder($module->id());
            $module->register($first);
            $second = new ModuleBuilder($module->id());
            $module->register($second);
        } finally {
            $watch->on = false;
        }

        Assert::assertSame(0, $watch->queries, sprintf('Module "%s" ran %d query/queries while declaring itself.', $module->id()->value, $watch->queries));
        Assert::assertSame([], $watch->resolved, sprintf('Module "%s" resolved %s from the container while declaring itself.', $module->id()->value, implode(', ', $watch->resolved)));
        Assert::assertSame($before, $this->footprint(), sprintf('Module "%s" bound, listened or configured something while declaring itself.', $module->id()->value));
        Assert::assertEquals($this->declarations($first), $this->declarations($second), sprintf('Module "%s" makes different declarations on each register().', $module->id()->value));
    }

    /**
     * What `register()` must leave as it found it: the container bindings, the number of listeners per event and the config.
     *
     * @return array{bindings: list<string>, listeners: array<string, int>, config: string}
     */
    private function footprint(): array
    {
        $bindings = array_map(strval(...), array_keys(App::getBindings()));
        $listeners = [];
        foreach (App::make('events')->getRawListeners() as $event => $registered) {
            $listeners[$event] = is_countable($registered) ? count($registered) : 1;
        }
        sort($bindings);
        ksort($listeners);

        return ['bindings' => $bindings, 'listeners' => $listeners, 'config' => serialize(App::make('config')->all())];
    }

    /** @param list<VaulterModule> $requires */
    private function assertCompiles(VaulterModule $module, array $requires): void
    {
        $id = $module->id()->value;
        $manifests = [new CoreModule, ...$requires, $module];

        $active = (new ModuleCompiler)->compile($manifests, [$id => true]);
        Assert::assertInstanceOf(CompiledModules::class, $active, sprintf(
            'Module "%s" does not compile when active: %s',
            $id,
            $active instanceof InvalidModuleGraph ? implode('; ', $active->reasons) : '',
        ));
        Assert::assertTrue($active->isActive($id), sprintf('Module "%s" is not active although it was switched on.', $id));

        $inactive = (new ModuleCompiler)->compile($manifests, [$id => false]);
        Assert::assertInstanceOf(CompiledModules::class, $inactive, sprintf(
            'Module "%s" breaks the graph when switched off: %s',
            $id,
            $inactive instanceof InvalidModuleGraph ? implode('; ', $inactive->reasons) : '',
        ));
        Assert::assertFalse($inactive->isActive($id), sprintf('Module "%s" is active although it was switched off.', $id));
        Assert::assertNotSame($active->fingerprint, $inactive->fingerprint, sprintf('Module "%s" does not change the graph fingerprint.', $id));
    }

    private function assertSealed(VaulterModule $module): void
    {
        $builder = new ModuleBuilder($module->id());
        $module->register($builder);
        $builder->seal();

        $late = [
            'config' => static fn (ModuleBuilder $b): ModuleBuilder => $b->config('late', 'late.php'),
            'schema' => static fn (ModuleBuilder $b): ModuleBuilder => $b->schema('late'),
            'routes' => static fn (ModuleBuilder $b): ModuleBuilder => $b->routes('late.php'),
            'translations' => static fn (ModuleBuilder $b): ModuleBuilder => $b->translations('late', 'late'),
            'commands' => static fn (ModuleBuilder $b): ModuleBuilder => $b->commands(self::class),
            'listen' => static fn (ModuleBuilder $b): ModuleBuilder => $b->listen(self::class, self::class),
            'schedule' => static fn (ModuleBuilder $b): ModuleBuilder => $b->schedule(ModuleSchedule::class),
            'routeMiddleware' => static fn (ModuleBuilder $b): ModuleBuilder => $b->routeMiddleware('late', self::class),
            'bindings' => static fn (ModuleBuilder $b): ModuleBuilder => $b->bindings(ModuleBindings::class),
            'defineCapability' => static fn (ModuleBuilder $b): ModuleBuilder => $b->defineCapability(self::class, Cardinality::Many),
            'provides' => static fn (ModuleBuilder $b): ModuleBuilder => $b->provides(self::class, self::class),
            'providesFromConfig' => static fn (ModuleBuilder $b): ModuleBuilder => $b->providesFromConfig(self::class, 'late.key'),
            'defineSlot' => static fn (ModuleBuilder $b): ModuleBuilder => $b->defineSlot(self::class, SlotKind::Handler),
            'slot' => static fn (ModuleBuilder $b): ModuleBuilder => $b->slot(self::class, self::class, 'late'),
            'events' => static fn (ModuleBuilder $b): ModuleBuilder => $b->events(self::class),
            'problems' => static fn (ModuleBuilder $b): ModuleBuilder => $b->problems(ProblemCodeSource::class),
            'operations' => static fn (ModuleBuilder $b): ModuleBuilder => $b->operations(OperationPolicySource::class),
        ];
        foreach ($late as $declaration => $declare) {
            try {
                $declare($builder);
                Assert::fail(sprintf('Module "%s" can still declare "%s" after the builder was sealed.', $module->id()->value, $declaration));
            } catch (RegistryFrozenException) {
                // A late declaration is refused.
            }
        }
    }

    /** @return array<string, mixed> */
    private function declarations(ModuleBuilder $builder): array
    {
        return [
            'configs' => $builder->configs(),
            'schema' => $builder->schemaPaths(),
            'routes' => $builder->routeFiles(),
            'translations' => $builder->translationDirs(),
            'commands' => $builder->commandClasses(),
            'listeners' => $builder->listeners(),
            'schedules' => $builder->schedules(),
            'routeMiddleware' => $builder->routeMiddlewareAliases(),
            'bindings' => $builder->bindingClasses(),
            'capabilityDefinitions' => $builder->capabilityDefinitions(),
            'capabilityProviders' => $builder->capabilityProviders(),
            'slotDefinitions' => $builder->slotDefinitions(),
            'contributions' => $builder->contributions(),
            'events' => $builder->eventSources(),
            'problems' => $builder->problemSources(),
            'operations' => $builder->operationSources(),
        ];
    }
}
