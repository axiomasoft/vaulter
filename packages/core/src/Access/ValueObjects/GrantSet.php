<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Enums\Ability;
use Vaulter\Enums\GrantLevel;

/**
 * The folded result of the effective-permission algorithm for one (actor, node)
 * pair (master plan H.2). It carries the granted allow-mask (max of ACL allow +
 * role allow), the denied-mask (OR of every deny that applies), and the effective
 * level — and answers `allows(Ability)` with the deny-veto baked in.
 *
 * The final veto (H.2 step 5) lives HERE: an ability is allowed only when its
 * required bits are all present in the allow-mask AND none of them is in the
 * deny-mask, so a host role grant can never resurrect a denied bit.
 *
 * `superAllow`/`superDeny` carry a host override (super-admin / Gate::before),
 * which short-circuits both directions absolutely (H.2 step 1).
 */
final readonly class GrantSet
{
    public function __construct(
        public int $allowedBits,
        public int $deniedBits,
        public ?GrantLevel $level = null,
        public bool $superAllow = false,
        public bool $superDeny = false,
    ) {}

    /**
     * An empty grant set — no access at all (the no-grant baseline).
     */
    public static function none(): self
    {
        return new self(allowedBits: 0, deniedBits: 0);
    }

    /**
     * A host super-admin override that allows everything, unconditionally.
     */
    public static function superAllowed(): self
    {
        return new self(
            allowedBits: 0,
            deniedBits: 0,
            level: GrantLevel::Owner,
            superAllow: true,
        );
    }

    /**
     * A host override that denies everything, unconditionally.
     */
    public static function superDenied(): self
    {
        return new self(allowedBits: 0, deniedBits: 0, superDeny: true);
    }

    /**
     * The effective decision for an ability with the deny-veto applied (H.2 step 5):
     *   1. a super-deny override loses to nothing → DENY;
     *   2. a super-allow override wins over everything → ALLOW;
     *   3. otherwise the required bits must ALL be allowed AND none denied.
     */
    public function allows(Ability $ability): bool
    {
        if ($this->superDeny) {
            return false;
        }

        if ($this->superAllow) {
            return true;
        }

        $required = $ability->requiredBits();

        // Final veto: any required bit landing in the deny-mask kills the ability,
        // regardless of the allow-mask (a deny denies the bit and everything that
        // implies it).
        if (($this->deniedBits & $required) !== 0) {
            return false;
        }

        return ($this->allowedBits & $required) === $required;
    }
}
