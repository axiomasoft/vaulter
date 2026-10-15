<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Api;

/**
 * How many active providers a capability, or contributions a slot, takes.
 */
#[Api]
enum Cardinality: string
{
    /** Exactly one. */
    case RequiredSingle = 'required-single';

    /** Zero or one. */
    case OptionalSingle = 'optional-single';

    /** Any number, in a deterministic order. */
    case Many = 'many';

    public function admits(int $count): bool
    {
        return match ($this) {
            self::RequiredSingle => $count === 1,
            self::OptionalSingle => $count <= 1,
            self::Many => true,
        };
    }
}
