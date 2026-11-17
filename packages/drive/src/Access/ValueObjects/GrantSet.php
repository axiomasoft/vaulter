<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\ValueObjects;

use Packman\Attributes\Api;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\Permission;

/**
 * The effective abilities of one actor on one node: the union of every role that
 * applies there. `superAllow`/`superDeny` carry a host override, which beats the
 * union in both directions.
 */
#[Api]
final readonly class GrantSet
{
    public function __construct(
        public int $allowedBits,
        public bool $superAllow = false,
        public bool $superDeny = false,
    ) {}

    /** No access at all. */
    public static function none(): self
    {
        return new self(allowedBits: 0);
    }

    /** A host super-user override: every ability. */
    public static function superAllowed(): self
    {
        return new self(allowedBits: Permission::all(), superAllow: true);
    }

    /** A host administrative block: nothing, whatever the grants say. */
    public static function superDenied(): self
    {
        return new self(allowedBits: 0, superDeny: true);
    }

    public function allows(Ability $ability): bool
    {
        if ($this->superDeny) {
            return false;
        }

        return $this->superAllow || $ability->satisfiedBy($this->allowedBits);
    }
}
