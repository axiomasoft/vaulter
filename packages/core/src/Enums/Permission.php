<?php

declare(strict_types=1);

namespace Vaulter\Enums;

/**
 * The Nextcloud-style CRUDS permission bitmask (master plan §2.6). Each case is a
 * single bit; the stored `permissions` integer on v_node_grants is an OR of these.
 * An {@see Ability} maps to the bit(s) it requires, and {@see GrantLevel} maps to
 * the bitmask a level confers — so the whole engine reasons over one int.
 *
 *   READ=1  UPDATE=2  CREATE=4  DELETE=8  SHARE=16  ALL=31
 */
enum Permission: int
{
    case Read = 1;
    case Update = 2;
    case Create = 4;
    case Delete = 8;
    case Share = 16;
    case All = 31;

    /**
     * The OR of every concrete CRUDS bit — the value of ALL (31). Computed from the
     * cases so a future bit cannot drift out of sync with this constant.
     */
    public static function allBits(): int
    {
        return self::Read->value
            | self::Update->value
            | self::Create->value
            | self::Delete->value
            | self::Share->value;
    }

    /**
     * Does the given bitmask contain every bit of $this permission? A request for a
     * compound permission (e.g. ALL) is only satisfied when all its bits are set.
     */
    public function isSetIn(int $bits): bool
    {
        return ($bits & $this->value) === $this->value;
    }
}
