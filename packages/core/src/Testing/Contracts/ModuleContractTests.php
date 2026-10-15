<?php

declare(strict_types=1);

namespace Vaulter\Testing\Contracts;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\CoreModule;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\VaulterModule;

/**
 * Reusable assertions for the manifest of a third-party module, to run from a test of the Laravel application
 * that has the module's package (and the packages it requires) installed.
 *
 * A manifest is declarations only: reading its identity and calling `register()` touches no database and
 * resolves nothing from the container, `register()` makes the same declarations every time, and the graph
 * of the core module, the modules the manifest requires and the manifest compiles, active or switched off.
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
        Assert::assertEquals($this->declarations($first), $this->declarations($second), sprintf('Module "%s" makes different declarations on each register().', $module->id()->value));
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

        try {
            $builder->routes('late.php');
            Assert::fail(sprintf('Module "%s" can still declare after the builder was sealed.', $module->id()->value));
        } catch (RegistryFrozenException) {
            // A late declaration is refused.
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
        ];
    }
}
