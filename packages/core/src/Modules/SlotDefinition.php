<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;

/**
 * A slot contract, the module that owns it, its kind and how many contributions it takes.
 */
#[Internal]
final readonly class SlotDefinition
{
    /** @param class-string $contract */
    public function __construct(
        public string $contract,
        public string $module,
        public SlotKind $kind,
        public Cardinality $cardinality,
    ) {}
}
