<?php

declare(strict_types=1);

namespace Vaulter\Enums\Access;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The ranked Drive access levels (master plan §2.6 / H.2). A higher rank implies
 * every lower-ranked level and carries a superset of CRUDS bits, so the engine can
 * collapse a level grant to a bitmask and reason over a single int alongside the
 * raw permissions column.
 *
 * The stored column value is reader|commenter|writer|organizer|owner (§2.6). Per
 * H.2 the old standalone "delete" action collapses into Organizer (and the Manage
 * ability) — there is no orphan stringly-typed delete level.
 *
 *   reader    → READ
 *   commenter → READ                 (comment is a view-side capability)
 *   writer    → READ|UPDATE|CREATE
 *   organizer → READ|UPDATE|CREATE|DELETE
 *   owner     → ALL (adds SHARE / management)
 */
enum GrantLevel: string
{
    use HasLabel;

    #[Label('Reader')]
    case Reader = 'reader';

    #[Label('Commenter')]
    case Commenter = 'commenter';

    #[Label('Writer')]
    case Writer = 'writer';

    #[Label('Organizer')]
    case Organizer = 'organizer';

    #[Label('Owner')]
    case Owner = 'owner';

    /**
     * The rank used to compare and max levels. Higher rank = broader access.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Reader => 1,
            self::Commenter => 2,
            self::Writer => 3,
            self::Organizer => 4,
            self::Owner => 5,
        };
    }

    /**
     * The CRUDS bitmask this level confers (§2.6). A grant row that stores only a
     * level (permissions=0) is normalised through here so allow/deny reasoning is
     * always bit-based.
     */
    public function bits(): int
    {
        return match ($this) {
            self::Reader => Permission::Read->value,
            self::Commenter => Permission::Read->value,
            self::Writer => Permission::Read->value | Permission::Update->value | Permission::Create->value,
            self::Organizer => Permission::Read->value
                | Permission::Update->value
                | Permission::Create->value
                | Permission::Delete->value,
            self::Owner => Permission::allBits(),
        };
    }

    /**
     * Does this level rank at or above $other (i.e. grant at least its abilities)?
     */
    public function implies(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * The higher of two levels (used to fold the allow-set down to one effective
     * level). Ties return $this.
     */
    public function max(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }
}
