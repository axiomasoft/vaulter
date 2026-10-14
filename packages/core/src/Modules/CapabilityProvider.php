<?php

declare(strict_types=1);

namespace Vaulter\Modules;

/**
 * One provider of a capability: an implementation class, or a host config key naming the class
 * ({@see ModuleBuilder::providesFromConfig()}).
 *
 * @internal
 */
final readonly class CapabilityProvider
{
    /**
     * @param class-string      $contract
     * @param class-string|null $implementation
     * @param class-string|null $default
     */
    public function __construct(
        public string $contract,
        public string $module,
        public ?string $implementation = null,
        public ?string $configKey = null,
        public ?string $default = null,
    ) {}

    public function fromConfig(): bool
    {
        return $this->configKey !== null;
    }
}
