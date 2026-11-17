<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Operations\OperationPolicySource;

/**
 * What a module declares from {@see VaulterModule::register()}. Declarations only: values are
 * scalars, enums and class-strings (no closures), nothing is resolved or read here, and every call
 * after the kernel sealed the registry throws {@see RegistryFrozenException}.
 *
 * The kernel applies the declarations of active modules after compilation; the schema of every
 * offered module, active or not, is installed.
 */
#[Api]
final class ModuleBuilder
{
    private bool $sealed = false;

    /** @var array<string, string> config root => defaults file */
    private array $configs = [];

    /** @var list<string> */
    private array $schemaPaths = [];

    /** @var list<string> */
    private array $routes = [];

    /** @var array<string, string> namespace => directory */
    private array $translations = [];

    /** @var list<class-string> */
    private array $commands = [];

    /** @var list<array{event: class-string, listener: class-string}> */
    private array $listeners = [];

    /** @var list<string> declared as given; the compiler checks them */
    private array $schedules = [];

    /** @var array<string, class-string> alias => middleware */
    private array $routeMiddleware = [];

    /** @var list<string> declared as given; the compiler checks them */
    private array $bindings = [];

    /** @var list<CapabilityDefinition> */
    private array $capabilityDefinitions = [];

    /** @var list<CapabilityProvider> */
    private array $capabilityProviders = [];

    /** @var list<SlotDefinition> */
    private array $slotDefinitions = [];

    /** @var list<SlotContribution> */
    private array $contributions = [];

    /** @var list<class-string> */
    private array $events = [];

    /** @var list<class-string<ProblemCodeSource>> */
    private array $problems = [];

    /** @var list<class-string<OperationPolicySource>> */
    private array $operations = [];

    public function __construct(private readonly ModuleId $module) {}

    public function module(): ModuleId
    {
        return $this->module;
    }

    /** The module owns the config root `$root`, with its defaults in `$defaultsFile`. */
    public function config(string $root, string $defaultsFile): self
    {
        $this->guard();
        $this->configs[$this->nonEmpty($root, 'config root')] = $this->nonEmpty($defaultsFile, 'config defaults file');

        return $this;
    }

    /** The module's migrations: installed whether the module is active or not. */
    public function schema(string $migrationsDir): self
    {
        $this->guard();
        $this->schemaPaths[] = $this->nonEmpty($migrationsDir, 'schema directory');

        return $this;
    }

    public function routes(string $file): self
    {
        $this->guard();
        $this->routes[] = $this->nonEmpty($file, 'routes file');

        return $this;
    }

    public function translations(string $dir, string $namespace): self
    {
        $this->guard();
        $this->translations[$this->nonEmpty($namespace, 'translation namespace')] = $this->nonEmpty($dir, 'translation directory');

        return $this;
    }

    /** @param class-string ...$commands */
    public function commands(string ...$commands): self
    {
        $this->guard();
        foreach ($commands as $command) {
            $this->commands[] = $command;
        }

        return $this;
    }

    /**
     * @param class-string $event
     * @param class-string $listener
     */
    public function listen(string $event, string $listener): self
    {
        $this->guard();
        $this->listeners[] = ['event' => $event, 'listener' => $listener];

        return $this;
    }

    /** @param class-string<ModuleSchedule> $schedule */
    public function schedule(string $schedule): self
    {
        $this->guard();
        $this->schedules[] = $schedule;

        return $this;
    }

    /** @param class-string $middleware */
    public function routeMiddleware(string $alias, string $middleware): self
    {
        $this->guard();
        $this->routeMiddleware[$this->nonEmpty($alias, 'middleware alias')] = $middleware;

        return $this;
    }

    /**
     * Container wiring that no declaration expresses; registered and booted only while the module is active.
     *
     * @param class-string<ModuleBindings> $bindings
     */
    public function bindings(string $bindings): self
    {
        $this->guard();
        $this->bindings[] = $bindings;

        return $this;
    }

    /**
     * The module owns the capability `$contract`; active providers must satisfy `$cardinality`.
     *
     * @param class-string $contract
     */
    public function defineCapability(string $contract, Cardinality $cardinality): self
    {
        $this->guard();
        $this->capabilityDefinitions[] = new CapabilityDefinition($contract, $this->module->value, $cardinality);

        return $this;
    }

    /**
     * @param class-string $contract
     * @param class-string $implementation
     */
    public function provides(string $contract, string $implementation): self
    {
        $this->guard();
        $this->capabilityProviders[] = new CapabilityProvider($contract, $this->module->value, implementation: $implementation);

        return $this;
    }

    /**
     * The host picks the implementation of `$contract` with the config key `$configKey` (a class-string
     * or null); `$default` is used while the key is empty. The configured class is validated by the
     * config validator, and read only when the capability is resolved.
     *
     * @param class-string      $contract
     * @param class-string|null $default
     */
    public function providesFromConfig(string $contract, string $configKey, ?string $default = null): self
    {
        $this->guard();
        $this->capabilityProviders[] = new CapabilityProvider(
            $contract,
            $this->module->value,
            configKey: $this->nonEmpty($configKey, 'config key'),
            default: $default,
        );

        return $this;
    }

    /** @param class-string $contract */
    public function defineSlot(string $contract, SlotKind $kind, Cardinality $cardinality = Cardinality::Many): self
    {
        $this->guard();
        $this->slotDefinitions[] = new SlotDefinition($contract, $this->module->value, $kind, $cardinality);

        return $this;
    }

    /**
     * Contribute `$implementation` to the slot `$contract` under the stable `$id`. Contributions run in
     * the order (`$priority` ascending, `$id`); a handler slot picks a contribution by `$key`.
     *
     * @param class-string $contract
     * @param class-string $implementation
     */
    public function slot(string $contract, string $implementation, string $id, int $priority = 0, ?string $key = null): self
    {
        $this->guard();
        $this->contributions[] = new SlotContribution($contract, $id, $this->module->value, $implementation, $priority, $key);

        return $this;
    }

    /**
     * The module's event types: a backed string enum implementing `EventTypeContract`, one case per type.
     * The event catalogue lists them while the module is active, and a value declared by two installed modules
     * makes the graph invalid.
     *
     * @param class-string $enum
     */
    public function events(string $enum): self
    {
        $this->guard();
        $this->events[] = $enum;

        return $this;
    }

    /**
     * The module's problem codes (architecture#errors): the catalogue lists them while the module is active, and a
     * code declared by two installed modules makes the graph invalid.
     *
     * @param class-string<ProblemCodeSource> $source
     */
    public function problems(string $source): self
    {
        $this->guard();
        $this->problems[] = $source;

        return $this;
    }

    /**
     * The policies of the module's mutating operations: the catalogue lists them while the module is active,
     * the operation runner refuses a mutating operation it does not list, and an operation name declared by two
     * installed modules makes the graph invalid.
     *
     * @param class-string<OperationPolicySource> $source
     */
    public function operations(string $source): self
    {
        $this->guard();
        $this->operations[] = $source;

        return $this;
    }

    /** Called by the compiler once every manifest registered. */
    #[Internal]
    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @return array<string, string> */
    public function configs(): array
    {
        return $this->configs;
    }

    /** @return list<string> */
    public function schemaPaths(): array
    {
        return $this->schemaPaths;
    }

    /** @return list<string> */
    public function routeFiles(): array
    {
        return $this->routes;
    }

    /** @return array<string, string> */
    public function translationDirs(): array
    {
        return $this->translations;
    }

    /** @return list<class-string> */
    public function commandClasses(): array
    {
        return $this->commands;
    }

    /** @return list<array{event: class-string, listener: class-string}> */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /** @return list<string> */
    public function schedules(): array
    {
        return $this->schedules;
    }

    /** @return array<string, class-string> */
    public function routeMiddlewareAliases(): array
    {
        return $this->routeMiddleware;
    }

    /** @return list<string> */
    public function bindingClasses(): array
    {
        return $this->bindings;
    }

    /** @return list<CapabilityDefinition> */
    #[Internal]
    public function capabilityDefinitions(): array
    {
        return $this->capabilityDefinitions;
    }

    /** @return list<CapabilityProvider> */
    #[Internal]
    public function capabilityProviders(): array
    {
        return $this->capabilityProviders;
    }

    /** @return list<SlotDefinition> */
    #[Internal]
    public function slotDefinitions(): array
    {
        return $this->slotDefinitions;
    }

    /** @return list<SlotContribution> */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /** @return list<class-string> */
    public function eventSources(): array
    {
        return $this->events;
    }

    /** @return list<string> the declared classes; the compiler checks that each one is a {@see ProblemCodeSource} */
    public function problemSources(): array
    {
        return $this->problems;
    }

    /** @return list<string> the declared classes; the compiler checks that each one is an {@see OperationPolicySource} */
    public function operationSources(): array
    {
        return $this->operations;
    }

    private function guard(): void
    {
        if ($this->sealed) {
            throw RegistryFrozenException::forRegistry('module ('.$this->module->value.')');
        }
    }

    private function nonEmpty(string $value, string $what): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('[Vaulter] Module %s declares an empty %s.', $this->module->value, $what));
        }

        return $value;
    }
}
