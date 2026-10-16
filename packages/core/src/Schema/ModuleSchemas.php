<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Kernel;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;

/**
 * The schema classes of the offered modules, active or not (the schema of every offered module is installed), in the
 * order of the module graph — a module after the ones it requires. The only place that walks every table of
 * the installation; nothing in the kernel lists a module's tables.
 */
#[Internal]
final readonly class ModuleSchemas
{
    public function __construct(
        private Kernel $kernel,
        private Container $container,
    ) {}

    /**
     * @return array<string, ModuleSchema> module id => its schema, for the modules that own tables, a module after the
     *                                     ones it requires (a table may reference a table of a required module)
     */
    public function all(): array
    {
        if (!$this->kernel->isValid()) {
            return [];
        }

        $manifests = [];
        foreach ($this->kernel->compiled()->modules as $state) {
            $manifests[$state->id->value] = $state->manifest;
        }

        $ordered = [];
        $visit = function (string $module) use (&$visit, &$ordered, $manifests): void {
            if (array_key_exists($module, $ordered) || !isset($manifests[$module])) {
                return;
            }
            $ordered[$module] = null;

            $manifest = $this->container->make($manifests[$module]);
            foreach ($manifest instanceof VaulterModule ? array_keys($manifest->requires()) : [] as $required) {
                $visit($required);
            }
            unset($ordered[$module]);
            $ordered[$module] = true;
        };
        foreach (array_keys($manifests) as $module) {
            $visit($module);
        }

        $schemas = [];
        foreach (array_keys($ordered) as $module) {
            if (!is_subclass_of($manifests[$module], OwnsSchema::class)) {
                continue;
            }

            $schema = $this->container->make($manifests[$module]::ownedSchema());
            if ($schema instanceof ModuleSchema) {
                $schemas[$module] = $schema;
            }
        }

        return $schemas;
    }

    /** @return list<string> the entity keys of the tables `$module` owns; empty for a module without tables */
    public function entitiesOf(string $module): array
    {
        return ($this->all()[$module] ?? null)?->entities() ?? [];
    }

    /** @return list<string> the entity keys of every table of every offered module */
    public function allEntities(): array
    {
        return array_merge([], ...array_values(array_map(static fn (ModuleSchema $schema): array => $schema->entities(), $this->all())));
    }

    /** Creates every table of every offered module, a module after the ones it requires. */
    public function createAll(): void
    {
        foreach ($this->all() as $schema) {
            foreach ($schema->entities() as $entity) {
                $schema->create($entity);
            }
        }
    }

    /** Drops every table of every offered module in the reverse order; each drop is guarded. */
    public function dropAll(): void
    {
        foreach (array_reverse($this->all()) as $schema) {
            foreach (array_reverse($schema->entities()) as $entity) {
                $schema->drop($entity);
            }
        }
    }
}
