<?php

declare(strict_types=1);

namespace Vaulter\Enums\Access;

/**
 * The internal bitmask behind {@see Ability}: exactly one bit per ability, so a
 * role's ability set folds into one int and a union of roles is a bitwise OR.
 * Grants and share links store role keys, never these numbers, so the bit layout
 * is free to grow; a bit is never reused for another ability.
 *
 *   VIEW=1  EDIT=2  CREATE=4  DELETE=8  SHARE=16  COMMENT=32  DOWNLOAD=64
 *   MOVE=128  COPY=256  TRASH=512  RESTORE=1024  MANAGE=2048
 */
enum Permission: int
{
    case View = 1;
    case Edit = 2;
    case Create = 4;
    case Delete = 8;
    case Share = 16;
    case Comment = 32;
    case Download = 64;
    case Move = 128;
    case Copy = 256;
    case Trash = 512;
    case Restore = 1024;
    case Manage = 2048;

    /**
     * The OR of every bit (4095).
     */
    public static function all(): int
    {
        $bits = 0;
        foreach (self::cases() as $case) {
            $bits |= $case->value;
        }

        return $bits;
    }

    /**
     * Does the given bitmask contain this bit?
     */
    public function isSetIn(int $bits): bool
    {
        return ($bits & $this->value) === $this->value;
    }
}
