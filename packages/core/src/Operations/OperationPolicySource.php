<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A module's contribution to the operation catalogue, declared with `ModuleBuilder::operations()`: the
 * policy of every mutating operation the module runs through the {@see OperationRunner}. The catalogue lists the
 * operations of active modules only; a name declared by two installed modules makes the module graph invalid
 * A source has no constructor arguments: the compiler reads its table.
 */
#[Spi(SpiKind::Implement)]
interface OperationPolicySource
{
    /**
     * @return array<string, OperationPolicy> operation name ({@see OperationSpec::$name}) => policy
     */
    public function policies(): array;
}
