<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Problems\ProblemCodeSource;

/**
 * The sealed outcome of a valid compilation: every offered module with its state, and the
 * declarations of the active ones. Holds only scalars, enums, class-strings and readonly values.
 */
#[Internal]
final readonly class CompiledModules
{
    /**
     * @param list<ModuleState>                                        $modules         every offered module, by id
     * @param array<class-string, CapabilityDefinition>                $capabilities    defined by active modules
     * @param array<class-string, list<CapabilityProvider>>            $providers       active providers per capability
     * @param array<class-string, SlotDefinition>                      $slots           defined by active modules
     * @param array<class-string, list<SlotContribution>>              $contributions   active contributions per slot, ordered
     * @param array<string, string>                                    $configs         config root => defaults file
     * @param list<array{module: string, file: string}>                $routes
     * @param array<string, string>                                    $translations    namespace => directory
     * @param list<class-string>                                       $commands
     * @param list<array{event: class-string, listener: class-string}> $listeners
     * @param list<class-string<ModuleSchedule>>                       $schedules
     * @param array<string, class-string>                              $routeMiddleware alias => middleware
     * @param list<class-string<ModuleBindings>>                       $bindings
     * @param array<string, list<class-string>>                        $events          active module id => event type enums (D38)
     * @param array<string, list<string>>                              $problems        active module id => source classes (each checked to be a ProblemCodeSource)
     * @param array<string, list<string>>                              $operations      active module id => operation policy sources (each checked to be an OperationPolicySource, D47)
     * @param list<string>                                             $schemaPaths     of every offered module, active or not
     */
    public function __construct(
        public array $modules,
        public array $capabilities,
        public array $providers,
        public array $slots,
        public array $contributions,
        public array $configs,
        public array $routes,
        public array $translations,
        public array $commands,
        public array $listeners,
        public array $schedules,
        public array $routeMiddleware,
        public array $bindings,
        public array $events,
        public array $problems,
        public array $operations,
        public array $schemaPaths,
        public string $fingerprint,
    ) {}

    public function module(string $id): ?ModuleState
    {
        foreach ($this->modules as $module) {
            if ($module->id->value === $id) {
                return $module;
            }
        }

        return null;
    }

    /** @return list<string> the ids of the active modules, sorted */
    public function activeIds(): array
    {
        $ids = [];
        foreach ($this->modules as $module) {
            if ($module->active) {
                $ids[] = $module->id->value;
            }
        }

        return $ids;
    }

    public function isActive(string $id): bool
    {
        return $this->module($id)->active ?? false;
    }
}
