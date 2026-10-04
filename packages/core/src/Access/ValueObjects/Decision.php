<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;

/**
 * A single resolved access statement (master plan H.2): a level + its effect +
 * the concrete CRUDS bits it grants or forbids. The PermissionDriver returns one to
 * express "this actor has Writer here" (allow) and the AccessResolver folds the
 * node-ACL rows into Decisions before combining them.
 *
 * Final readonly VO.
 */
final readonly class Decision
{
    public function __construct(
        public GrantLevel $level,
        public Effect $effect,
        public int $bits,
    ) {}

    /**
     * An allow decision for a level, defaulting its bits to the level's bitmask.
     */
    public static function allow(GrantLevel $level, ?int $bits = null): self
    {
        return new self(level: $level, effect: Effect::Allow, bits: $bits ?? $level->bits());
    }

    /**
     * A deny decision for a level, defaulting its bits to the level's bitmask.
     */
    public static function deny(GrantLevel $level, ?int $bits = null): self
    {
        return new self(level: $level, effect: Effect::Deny, bits: $bits ?? $level->bits());
    }

    public function isAllow(): bool
    {
        return $this->effect === Effect::Allow;
    }

    public function isDeny(): bool
    {
        return $this->effect === Effect::Deny;
    }
}
