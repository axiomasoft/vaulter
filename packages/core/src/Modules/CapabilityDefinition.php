<?php

declare(strict_types=1);

namespace Vaulter\Modules;

/**
 * A capability contract and the module that defines it.
 *
 * @internal
 */
final readonly class CapabilityDefinition
{
    /** @param class-string $contract */
    public function __construct(
        public string $contract,
        public string $module,
        public Cardinality $cardinality,
    ) {}
}
