<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Modules\Capabilities;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ExtensionRegistry;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Modules\ModuleState;

/**
 * The compiled module graph of the application (ADR-0027): which modules are active, what they
 * provide and contribute. Sealed once by the core provider before any provider boots; an invalid
 * graph activates nothing and every read of capabilities or slots throws {@see ModuleGraphException}.
 */
#[Api]
final class Kernel
{
    private CompiledModules|InvalidModuleGraph|null $state = null;

    private ?Capabilities $capabilities = null;

    private ?ExtensionRegistry $extensions = null;

    #[Internal]
    public function __construct(private readonly Container $container) {}

    /** Called once by the core provider's booting callback. */
    #[Internal]
    public function seal(CompiledModules|InvalidModuleGraph $state): void
    {
        if ($this->state !== null) {
            throw RegistryFrozenException::forRegistry('module');
        }
        $this->state = $state;
    }

    public function isSealed(): bool
    {
        return $this->state !== null;
    }

    public function isValid(): bool
    {
        return $this->state instanceof CompiledModules;
    }

    /** @throws ModuleGraphException */
    public function assertValid(): void
    {
        $this->compiled();
    }

    /**
     * Why the graph is invalid; empty for a valid or not yet compiled graph.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->state instanceof InvalidModuleGraph ? $this->state->reasons : [];
    }

    /**
     * Every offered module and whether it is active; empty while the graph is invalid.
     *
     * @return list<ModuleState>
     */
    public function modules(): array
    {
        return $this->state instanceof CompiledModules ? $this->state->modules : [];
    }

    /** Whether the module `$id` is offered and active; false for an invalid or not yet compiled graph. */
    public function isActive(string $id): bool
    {
        return $this->state instanceof CompiledModules && $this->state->isActive($id);
    }

    public function fingerprint(): ?string
    {
        return $this->state instanceof CompiledModules ? $this->state->fingerprint : null;
    }

    /** @throws ModuleGraphException */
    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= new Capabilities($this->compiled(), $this->container);
    }

    /** @throws ModuleGraphException */
    public function extensions(): ExtensionRegistry
    {
        return $this->extensions ??= new ExtensionRegistry($this->compiled(), $this->container);
    }

    /**
     * the sealed declarations, for the kernel's own wiring
     *
     * @throws ModuleGraphException
     */
    #[Internal]
    public function compiled(): CompiledModules
    {
        return match (true) {
            $this->state instanceof CompiledModules => $this->state,
            $this->state instanceof InvalidModuleGraph => throw ModuleGraphException::invalid($this->state->reasons),
            default => throw ModuleGraphException::notCompiled(),
        };
    }
}
