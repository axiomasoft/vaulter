<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Events\EventTypeContract;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;
use Vaulter\Operations\OperationSpec;

/**
 * Compiles the offered manifests into one sealed module graph (architecture#module-system, steps 1–5).
 * A pure function of its input: no container, database, network, clock or config repository — the
 * `vaulter.modules` overrides come in as a value. Any defect yields {@see InvalidModuleGraph} with every
 * reason; nothing is activated partially.
 *
 * The only probes are `is_a()` checks of declared class-strings and the constant tables of the declared
 * problem and operation policy sources: config validation, never activation.
 */
#[Internal]
final class ModuleCompiler
{
    private const string CONSTRAINT = '/^\^(\d+)\.(\d+)$/';

    private const string VERSION = '/^(\d+)\.(\d+)$/';

    private const string CONTRIBUTION_ID = '/^[a-z0-9][a-z0-9._-]*$/';

    private const string PROBLEM_CODE = '/^[a-z][a-z0-9_]*$/';

    private const string EVENT_TYPE = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * Instantiates the offered manifest classes, then compiles them.
     *
     * @param list<string> $manifests class-strings offered by the providers
     */
    public function compileOffered(array $manifests, mixed $overrides): CompiledModules|InvalidModuleGraph
    {
        $reasons = [];
        $modules = [];
        foreach (array_values(array_unique($manifests)) as $class) {
            if (!is_a($class, VaulterModule::class, true)) {
                $reasons[] = sprintf('%s is offered as a module but does not implement %s', $class, VaulterModule::class);

                continue;
            }
            try {
                $modules[] = new $class;
            } catch (Throwable $e) {
                $reasons[] = sprintf('%s cannot be instantiated: %s', $class, $e->getMessage());
            }
        }

        if (!is_array($overrides)) {
            $reasons[] = 'vaulter.modules must be an array of module id => bool';
            $overrides = [];
        }

        $result = $this->compile($modules, $overrides);
        if ($reasons === []) {
            return $result;
        }

        return new InvalidModuleGraph([...$reasons, ...($result instanceof InvalidModuleGraph ? $result->reasons : [])]);
    }

    /**
     * @param list<VaulterModule> $modules
     * @param array<mixed>        $overrides `vaulter.modules`: module id => bool
     */
    public function compile(array $modules, array $overrides): CompiledModules|InvalidModuleGraph
    {
        $reasons = [];

        // 1. Identity, formats, overrides.
        /** @var array<string, array{manifest: VaulterModule, version: string, activation: Activation, requires: array<string, string>}> $known */
        $known = [];
        foreach ($modules as $manifest) {
            $read = $this->readManifest($manifest, $reasons);
            if ($read === null) {
                continue;
            }
            $id = $read['id'];
            if (isset($known[$id])) {
                $reasons[] = sprintf('module id "%s" is declared by both %s and %s', $id, $known[$id]['manifest']::class, $manifest::class);

                continue;
            }
            $known[$id] = ['manifest' => $manifest, 'version' => $read['version'], 'activation' => $read['activation'], 'requires' => $read['requires']];
        }
        ksort($known, SORT_STRING);

        /** @var array<string, bool> $override */
        $override = [];
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !isset($known[$key])) {
                $reasons[] = sprintf('vaulter.modules.%s names no installed module', (string) $key);

                continue;
            }
            if (!is_bool($value)) {
                $reasons[] = sprintf('vaulter.modules.%s must be true or false, %s given', $key, get_debug_type($value));

                continue;
            }
            $override[$key] = $value;
        }

        /** @var array<string, ModuleBuilder> $builders */
        $builders = [];
        foreach ($known as $id => $module) {
            $builders[$id] = $builder = new ModuleBuilder(new ModuleId($id));
            try {
                $module['manifest']->register($builder);
            } catch (Throwable $e) {
                $reasons[] = sprintf('module "%s" failed to declare itself: %s', $id, $e->getMessage());
            }
        }
        foreach ($builders as $builder) {
            $builder->seal();
        }

        // 2. Activation and the requires graph of the active modules.
        $active = [];
        foreach ($known as $id => $module) {
            $active[$id] = $override[$id] ?? ($module['activation'] === Activation::Auto);
        }
        foreach ($known as $id => $module) {
            if (!$active[$id]) {
                continue;
            }
            foreach ($module['requires'] as $dependency => $constraint) {
                if (!isset($known[$dependency])) {
                    $reasons[] = sprintf('module "%s" requires "%s", which is not installed', $id, $dependency);
                } elseif (!$active[$dependency]) {
                    $reasons[] = sprintf('module "%s" is active but requires "%s", which is not active', $id, $dependency);
                } elseif (!$this->satisfies($known[$dependency]['version'], $constraint)) {
                    $reasons[] = sprintf('module "%s" requires "%s" %s, installed protocol is %s', $id, $dependency, $constraint, $known[$dependency]['version']);
                }
            }
        }
        foreach ($this->cycles($known, $active) as $cycle) {
            $reasons[] = 'the requires graph has a cycle: '.implode(' -> ', $cycle);
        }

        // Ownership of names shared by all installed modules.
        $this->unique($builders, static fn (ModuleBuilder $b): array => array_keys($b->configs()), 'config root', $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => array_keys($b->translationDirs()), 'translation namespace', $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => array_keys($b->routeMiddlewareAliases()), 'route middleware alias', $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => $b->commandClasses(), 'command', $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => $b->bindingClasses(), 'bindings class', $reasons);
        $problemCodes = $this->problemCodes($builders, $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => $problemCodes[$b->module()->value] ?? [], 'problem code', $reasons);
        $eventTypes = $this->eventTypes($builders, $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => $eventTypes[$b->module()->value] ?? [], 'event type', $reasons);
        $operations = $this->operationNames($builders, $reasons);
        $this->unique($builders, static fn (ModuleBuilder $b): array => $operations[$b->module()->value] ?? [], 'operation', $reasons);
        foreach ($builders as $id => $builder) {
            foreach ($builder->bindingClasses() as $class) {
                if (!is_a($class, ModuleBindings::class, true)) {
                    $reasons[] = sprintf('module "%s" declares the bindings %s, which does not implement %s', $id, $class, ModuleBindings::class);
                }
            }
            foreach ($builder->schedules() as $class) {
                if (!is_a($class, ModuleSchedule::class, true)) {
                    $reasons[] = sprintf('module "%s" declares the schedule %s, which does not implement %s', $id, $class, ModuleSchedule::class);
                }
            }
        }

        $requires = static fn (string $module, string $owner): bool => $module === $owner || isset($known[$module]['requires'][$owner]);

        // 3. Capabilities.
        /** @var array<class-string, CapabilityDefinition> $definitions */
        $definitions = [];
        foreach ($builders as $builder) {
            foreach ($builder->capabilityDefinitions() as $definition) {
                if (isset($definitions[$definition->contract])) {
                    $reasons[] = sprintf('capability %s is defined by both "%s" and "%s"', $definition->contract, $definitions[$definition->contract]->module, $definition->module);

                    continue;
                }
                $definitions[$definition->contract] = $definition;
            }
        }
        /** @var array<class-string, list<CapabilityProvider>> $providers */
        $providers = [];
        foreach ($builders as $builder) {
            foreach ($builder->capabilityProviders() as $provider) {
                $definition = $definitions[$provider->contract] ?? null;
                if ($definition === null) {
                    $reasons[] = sprintf('module "%s" provides %s, which no module defines', $provider->module, $provider->contract);

                    continue;
                }
                if (!$requires($provider->module, $definition->module)) {
                    $reasons[] = sprintf('module "%s" provides %s but does not require "%s", which defines it', $provider->module, $provider->contract, $definition->module);
                }
                foreach ([$provider->implementation, $provider->default] as $class) {
                    if ($class !== null && !is_a($class, $provider->contract, true)) {
                        $reasons[] = sprintf('module "%s" provides %s with %s, which does not implement it', $provider->module, $provider->contract, $class);
                    }
                }
                if ($active[$provider->module] && $active[$definition->module]) {
                    $providers[$provider->contract][] = $provider;
                }
            }
        }
        foreach ($definitions as $contract => $definition) {
            if (!$active[$definition->module]) {
                unset($definitions[$contract]);

                continue;
            }
            $count = count($providers[$contract] ?? []);
            if (!$definition->cardinality->admits($count)) {
                $reasons[] = sprintf('capability %s (%s) has %d active providers', $contract, $definition->cardinality->value, $count);
            }
        }

        // 4. Slots.
        /** @var array<class-string, SlotDefinition> $slots */
        $slots = [];
        foreach ($builders as $builder) {
            foreach ($builder->slotDefinitions() as $slot) {
                if (isset($slots[$slot->contract])) {
                    $reasons[] = sprintf('slot %s is defined by both "%s" and "%s"', $slot->contract, $slots[$slot->contract]->module, $slot->module);

                    continue;
                }
                $slots[$slot->contract] = $slot;
            }
        }
        /** @var array<class-string, list<SlotContribution>> $contributions */
        $contributions = [];
        foreach ($builders as $builder) {
            foreach ($builder->contributions() as $contribution) {
                $slot = $slots[$contribution->slot] ?? null;
                if ($slot === null) {
                    $reasons[] = sprintf('module "%s" contributes "%s" to the unknown slot %s', $contribution->module, $contribution->id, $contribution->slot);

                    continue;
                }
                if (preg_match(self::CONTRIBUTION_ID, $contribution->id) !== 1) {
                    $reasons[] = sprintf('module "%s" contributes to %s under the malformed id "%s"', $contribution->module, $contribution->slot, $contribution->id);
                }
                if (!$requires($contribution->module, $slot->module)) {
                    $reasons[] = sprintf('module "%s" contributes "%s" to %s but does not require "%s", which defines it', $contribution->module, $contribution->id, $contribution->slot, $slot->module);
                }
                if (!is_a($contribution->implementation, $contribution->slot, true)) {
                    $reasons[] = sprintf('contribution "%s" to %s is %s, which does not implement it', $contribution->id, $contribution->slot, $contribution->implementation);
                }
                if ($slot->kind === SlotKind::Handler && ($contribution->key === null || $contribution->key === '')) {
                    $reasons[] = sprintf('contribution "%s" to the handler slot %s has no key', $contribution->id, $contribution->slot);
                }
                if ($active[$contribution->module] && $active[$slot->module]) {
                    $contributions[$contribution->slot][] = $contribution;
                }
            }
        }
        foreach ($slots as $contract => $slot) {
            if (!$active[$slot->module]) {
                unset($slots[$contract]);

                continue;
            }
            $list = $contributions[$contract] ?? [];
            usort($list, static fn (SlotContribution $a, SlotContribution $b): int => $a->priority <=> $b->priority ?: strcmp($a->id, $b->id));
            $contributions[$contract] = $list;
            $this->duplicates(array_map(static fn (SlotContribution $c): string => $c->id, $list), sprintf('slot %s has the contribution id', $contract), $reasons);
            $this->duplicates(array_values(array_filter(array_map(static fn (SlotContribution $c): ?string => $c->key, $list), static fn (?string $key): bool => $key !== null)), sprintf('slot %s has the key', $contract), $reasons);
            if (!$slot->cardinality->admits(count($list))) {
                $reasons[] = sprintf('slot %s (%s) has %d active contributions', $contract, $slot->cardinality->value, count($list));
            }
        }
        ksort($definitions, SORT_STRING);
        ksort($providers, SORT_STRING);
        ksort($slots, SORT_STRING);
        ksort($contributions, SORT_STRING);

        if ($reasons !== []) {
            return new InvalidModuleGraph(array_values(array_unique($reasons)));
        }

        // 5. The sealed state: the declarations of the active modules, the schema of all of them.
        $states = [];
        $configs = $translations = $routeMiddleware = [];
        $routes = $commands = $listeners = $schedules = $bindings = $events = $problems = $operationSources = $schemaPaths = [];
        foreach ($known as $id => $module) {
            $builder = $builders[$id];
            $states[] = new ModuleState(
                new ModuleId($id),
                $module['manifest']::class,
                $module['version'],
                $module['activation'],
                $active[$id],
                isset($override[$id]) ? ModuleState::REASON_OVERRIDE : ModuleState::REASON_DEFAULT,
                $builder->schemaPaths(),
            );
            array_push($schemaPaths, ...$builder->schemaPaths());
            if (!$active[$id]) {
                continue;
            }
            $configs += $builder->configs();
            $translations += $builder->translationDirs();
            $routeMiddleware += $builder->routeMiddlewareAliases();
            foreach ($builder->routeFiles() as $file) {
                $routes[] = ['module' => $id, 'file' => $file];
            }
            array_push($commands, ...$builder->commandClasses());
            array_push($listeners, ...$builder->listeners());
            // Classes that do not implement the contract are already reasons of an invalid graph.
            foreach ($builder->schedules() as $class) {
                if (is_subclass_of($class, ModuleSchedule::class)) {
                    $schedules[] = $class;
                }
            }
            foreach ($builder->bindingClasses() as $class) {
                if (is_subclass_of($class, ModuleBindings::class)) {
                    $bindings[] = $class;
                }
            }
            if ($builder->eventSources() !== []) {
                $events[$id] = array_values(array_unique($builder->eventSources()));
            }
            if ($builder->problemSources() !== []) {
                $problems[$id] = $builder->problemSources();
            }
            if ($builder->operationSources() !== []) {
                $operationSources[$id] = array_values(array_unique($builder->operationSources()));
            }
        }

        return new CompiledModules(
            modules: $states,
            capabilities: $definitions,
            providers: $providers,
            slots: $slots,
            contributions: $contributions,
            configs: $configs,
            routes: $routes,
            translations: $translations,
            commands: $commands,
            listeners: $listeners,
            schedules: $schedules,
            routeMiddleware: $routeMiddleware,
            bindings: $bindings,
            events: $events,
            problems: $problems,
            operations: $operationSources,
            schemaPaths: $schemaPaths,
            fingerprint: self::fingerprint($states, $override),
        );
    }

    /**
     * sha256 over the sorted (id, manifest, protocol, active) of every module and the canonical overrides:
     * stable across the order of offers, changed by any activation.
     *
     * @param list<ModuleState>   $states
     * @param array<string, bool> $overrides
     */
    public static function fingerprint(array $states, array $overrides): string
    {
        $entries = array_map(static fn (ModuleState $s): array => [$s->id->value, $s->manifest, $s->version, $s->active], $states);
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        ksort($overrides, SORT_STRING);

        return hash('sha256', json_encode([
            'modules' => $entries,
            'overrides' => hash('sha256', json_encode($overrides, JSON_THROW_ON_ERROR)),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Reads the identity of a manifest; a malformed protocol or requirement is a reason, the module stays
     * known so that its dependants and overrides are not reported twice.
     *
     * @param  list<string>                                                                                     $reasons
     * @return array{id: string, version: string, activation: Activation, requires: array<string, string>}|null
     */
    private function readManifest(VaulterModule $manifest, array &$reasons): ?array
    {
        $class = $manifest::class;
        try {
            $id = $manifest->id()->value;
            $version = $manifest->version();
            $activation = $manifest->activation();
            $requires = $manifest->requires();
        } catch (Throwable $e) {
            $reasons[] = sprintf('%s has an invalid manifest: %s', $class, $e->getMessage());

            return null;
        }

        if (preg_match(self::VERSION, $version) !== 1) {
            $reasons[] = sprintf('module "%s" declares the protocol "%s", expected MAJOR.MINOR', $id, $version);
        }
        /** @var array<string, string> $clean */
        $clean = [];
        foreach ($requires as $dependency => $constraint) {
            if (!ModuleId::isValid($dependency)) {
                $reasons[] = sprintf('module "%s" requires the malformed module id "%s"', $id, $dependency);

                continue;
            }
            if (preg_match(self::CONSTRAINT, $constraint) !== 1) {
                $reasons[] = sprintf('module "%s" requires "%s" with the unsupported constraint "%s", expected ^MAJOR.MINOR', $id, $dependency, $constraint);

                continue;
            }
            if ($dependency === $id) {
                $reasons[] = sprintf('module "%s" requires itself', $id);

                continue;
            }
            $clean[$dependency] = $constraint;
        }
        ksort($clean, SORT_STRING);

        return ['id' => $id, 'version' => $version, 'activation' => $activation, 'requires' => $clean];
    }

    /** `^M.m` holds for the same major and a minor of at least m. */
    private function satisfies(string $version, string $constraint): bool
    {
        preg_match(self::VERSION, $version, $have);
        preg_match(self::CONSTRAINT, $constraint, $want);

        return isset($have[1], $have[2], $want[1], $want[2])
            && (int) $have[1] === (int) $want[1]
            && (int) $have[2] >= (int) $want[2];
    }

    /**
     * @param  array<string, array{requires: array<string, string>}> $known
     * @param  array<string, bool>                                   $active
     * @return list<list<string>>
     */
    private function cycles(array $known, array $active): array
    {
        $cycles = [];
        $state = [];
        foreach (array_keys($known) as $id) {
            if ($active[$id] && !isset($state[$id])) {
                $this->visit((string) $id, [], $known, $active, $state, $cycles);
            }
        }

        return $cycles;
    }

    /**
     * @param list<string>                                          $path
     * @param array<string, array{requires: array<string, string>}> $known
     * @param array<string, bool>                                   $active
     * @param array<string, string>                                 $state
     * @param list<list<string>>                                    $cycles
     */
    private function visit(string $id, array $path, array $known, array $active, array &$state, array &$cycles): void
    {
        $state[$id] = 'open';
        $path[] = $id;
        foreach (array_keys($known[$id]['requires']) as $dependency) {
            $dependency = (string) $dependency;
            if (!isset($known[$dependency]) || !$active[$dependency]) {
                continue;
            }
            if (($state[$dependency] ?? null) === 'open') {
                $cycles[] = [...array_slice($path, (int) array_search($dependency, $path, true)), $dependency];
            } elseif (!isset($state[$dependency])) {
                $this->visit($dependency, $path, $known, $active, $state, $cycles);
            }
        }
        $state[$id] = 'done';
    }

    /**
     * @param array<string, ModuleBuilder>          $builders
     * @param callable(ModuleBuilder): list<string> $names
     * @param list<string>                          $reasons
     */
    private function unique(array $builders, callable $names, string $what, array &$reasons): void
    {
        $owner = [];
        foreach ($builders as $id => $builder) {
            foreach (array_unique($names($builder)) as $name) {
                if (isset($owner[$name])) {
                    $reasons[] = sprintf('%s "%s" is declared by both "%s" and "%s"', $what, $name, $owner[$name], $id);

                    continue;
                }
                $owner[$name] = $id;
            }
        }
    }

    /**
     * The problem codes each module declares. A source is a value object without constructor arguments whose
     * `codes()` is a constant table, so reading it here keeps the compiler free of container and I/O.
     *
     * @param  array<string, ModuleBuilder> $builders
     * @param  list<string>                 $reasons
     * @return array<string, list<string>>  module id => codes
     */
    private function problemCodes(array $builders, array &$reasons): array
    {
        $codes = [];
        foreach ($builders as $id => $builder) {
            foreach ($builder->problemSources() as $class) {
                if (!is_a($class, ProblemCodeSource::class, true)) {
                    $reasons[] = sprintf('module "%s" declares the problem source %s, which does not implement %s', $id, $class, ProblemCodeSource::class);

                    continue;
                }
                try {
                    $table = self::declaredCodes(new $class);
                } catch (Throwable $e) {
                    $reasons[] = sprintf('module "%s" declares the problem source %s, which cannot be read: %s', $id, $class, $e->getMessage());

                    continue;
                }
                foreach ($table as $code => $spec) {
                    if (!is_string($code) || preg_match(self::PROBLEM_CODE, $code) !== 1) {
                        $reasons[] = sprintf('problem source %s declares the malformed code "%s"', $class, (string) $code);

                        continue;
                    }
                    if (!$spec instanceof ProblemSpec) {
                        $reasons[] = sprintf('problem source %s declares "%s" without a %s', $class, $code, ProblemSpec::class);

                        continue;
                    }
                    if (in_array($code, $codes[$id] ?? [], true)) {
                        $reasons[] = sprintf('module "%s" declares the problem code "%s" twice (%s)', $id, $code, $class);

                        continue;
                    }
                    $codes[$id][] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * The event type values each module declares: every declared class is a backed string enum
     * implementing {@see EventTypeContract}; reading its cases keeps the compiler free of container and I/O.
     *
     * @param  array<string, ModuleBuilder> $builders
     * @param  list<string>                 $reasons
     * @return array<string, list<string>>  module id => values
     */
    private function eventTypes(array $builders, array &$reasons): array
    {
        $values = [];
        foreach ($builders as $id => $builder) {
            foreach (array_unique($builder->eventSources()) as $class) {
                if (!enum_exists($class) || !is_subclass_of($class, EventTypeContract::class)) {
                    $reasons[] = sprintf('module "%s" declares the event types %s, which is not an enum implementing %s', $id, $class, EventTypeContract::class);

                    continue;
                }
                foreach ($class::cases() as $case) {
                    // A non-string backing value (an int enum) is a malformed type, not a TypeError.
                    $value = (string) $case->value;
                    if (preg_match(self::EVENT_TYPE, $value) !== 1) {
                        $reasons[] = sprintf('event types %s declare the malformed type "%s"', $class, $value);

                        continue;
                    }
                    if (in_array($value, $values[$id] ?? [], true)) {
                        $reasons[] = sprintf('module "%s" declares the event type "%s" twice (%s)', $id, $value, $class);

                        continue;
                    }
                    $values[$id][] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * The operation names each module declares a policy for. A source is a value object without constructor
     * arguments whose `policies()` is a constant table; the aggregate of a policy belongs to the declaring module.
     *
     * @param  array<string, ModuleBuilder> $builders
     * @param  list<string>                 $reasons
     * @return array<string, list<string>>  module id => operation names
     */
    private function operationNames(array $builders, array &$reasons): array
    {
        $names = [];
        foreach ($builders as $id => $builder) {
            foreach (array_unique($builder->operationSources()) as $class) {
                if (!is_a($class, OperationPolicySource::class, true)) {
                    $reasons[] = sprintf('module "%s" declares the operation source %s, which does not implement %s', $id, $class, OperationPolicySource::class);

                    continue;
                }
                try {
                    $table = self::declaredPolicies(new $class);
                } catch (Throwable $e) {
                    $reasons[] = sprintf('module "%s" declares the operation source %s, which cannot be read: %s', $id, $class, $e->getMessage());

                    continue;
                }
                foreach ($table as $name => $policy) {
                    if (!is_string($name) || strlen($name) > 64 || preg_match(OperationSpec::NAME_PATTERN, $name) !== 1) {
                        $reasons[] = sprintf('operation source %s declares the malformed operation "%s"', $class, (string) $name);

                        continue;
                    }
                    if (!$policy instanceof OperationPolicy) {
                        $reasons[] = sprintf('operation source %s declares "%s" without an %s', $class, $name, OperationPolicy::class);

                        continue;
                    }
                    // Still counted as declared: a name two modules declare is reported as such too.
                    if ($policy->module() !== $id) {
                        $reasons[] = sprintf('module "%s" declares the operation "%s" on the aggregate "%s" of another module', $id, $name, $policy->aggregate);
                    }
                    if (in_array($name, $names[$id] ?? [], true)) {
                        $reasons[] = sprintf('module "%s" declares the operation "%s" twice (%s)', $id, $name, $class);

                        continue;
                    }
                    $names[$id][] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * What an operation source returns, read without trusting its declared shape.
     *
     * @return array<array-key, mixed>
     */
    private static function declaredPolicies(OperationPolicySource $source): array
    {
        return $source->policies();
    }

    /**
     * What a source returns, read without trusting its declared shape: the compiler is the one that rejects a
     * malformed table.
     *
     * @return array<array-key, mixed>
     */
    private static function declaredCodes(ProblemCodeSource $source): array
    {
        return $source->codes();
    }

    /**
     * @param list<string> $values
     * @param list<string> $reasons
     */
    private function duplicates(array $values, string $what, array &$reasons): void
    {
        foreach (array_keys(array_filter(array_count_values($values), static fn (int $n): bool => $n > 1)) as $value) {
            $reasons[] = sprintf('%s "%s" more than once', $what, $value);
        }
    }
}
