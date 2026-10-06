<?php

declare(strict_types=1);

namespace Vaulter\Enums\Access;

/**
 * The Nextcloud-style CRUDS permission bitmask (master plan §2.6). Each case is a
 * single bit; the stored `permissions` integer on v_node_grants is an OR of these.
 * An {@see Ability} maps to the bit(s) it requires, and {@see GrantLevel} maps to
 * the bitmask a level confers — so the whole engine reasons over one int.
 *
 *   READ=1  UPDATE=2  CREATE=4  DELETE=8  SHARE=16  COMMENT=32
 */
enum Permission: int
{
    case Read = 1;
    case Update = 2;
    case Create = 4;
    case Delete = 8;
    case Share = 16;
    case Comment = 32;

    /**
     * The OR of all six permission bits (63).
     */
    public static function all(): int
    {
        return self::Read->value
            | self::Update->value
            | self::Create->value
            | self::Delete->value
            | self::Share->value
            | self::Comment->value;
    }

    /**
     * Does the given bitmask contain every bit of $this permission? A request for a
     * permission is only satisfied when all its bits are set.
     */
    public function isSetIn(int $bits): bool
    {
        return ($bits & $this->value) === $this->value;
    }
}
