<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

/**
 * PostgreSQL 16 hashtext() — lookup3 hash_bytes, returned as int4.
 *
 * BlobLock asks for hashtext(scope|sha256). This is that function, not a
 * stand-in: the signed result matches SELECT hashtext(?) on PostgreSQL 16.
 */
final class ConcurrencyHashtext
{
    public static function int4(string $key): int
    {
        $unsigned = self::hashBytes($key);

        return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
    }

    private static function hashBytes(string $key): int
    {
        $length = strlen($key);
        $a = $b = $c = self::u32(0x9E3779B9 + $length + 3923095);
        $offset = 0;

        while ($length - $offset >= 12) {
            $a = self::u32($a + self::word($key, $offset));
            $b = self::u32($b + self::word($key, $offset + 4));
            $c = self::u32($c + self::word($key, $offset + 8));
            self::mix($a, $b, $c);
            $offset += 12;
        }

        $left = $length - $offset;

        if ($left >= 11) {
            $c = self::u32($c + (ord($key[$offset + 10]) << 24));
        }
        if ($left >= 10) {
            $c = self::u32($c + (ord($key[$offset + 9]) << 16));
        }
        if ($left >= 9) {
            $c = self::u32($c + (ord($key[$offset + 8]) << 8));
        }
        if ($left >= 8) {
            $b = self::u32($b + (ord($key[$offset + 7]) << 24));
        }
        if ($left >= 7) {
            $b = self::u32($b + (ord($key[$offset + 6]) << 16));
        }
        if ($left >= 6) {
            $b = self::u32($b + (ord($key[$offset + 5]) << 8));
        }
        if ($left >= 5) {
            $b = self::u32($b + ord($key[$offset + 4]));
        }
        if ($left >= 4) {
            $a = self::u32($a + (ord($key[$offset + 3]) << 24));
        }
        if ($left >= 3) {
            $a = self::u32($a + (ord($key[$offset + 2]) << 16));
        }
        if ($left >= 2) {
            $a = self::u32($a + (ord($key[$offset + 1]) << 8));
        }
        if ($left >= 1) {
            $a = self::u32($a + ord($key[$offset]));
        }

        self::finalise($a, $b, $c);

        return self::u32($c);
    }

    private static function word(string $key, int $offset): int
    {
        return ord($key[$offset])
            + (ord($key[$offset + 1]) << 8)
            + (ord($key[$offset + 2]) << 16)
            + (ord($key[$offset + 3]) << 24);
    }

    private static function mix(int &$a, int &$b, int &$c): void
    {
        $a = self::u32($a - $c);
        $a = self::u32($a ^ self::rot($c, 4));
        $c = self::u32($c + $b);
        $b = self::u32($b - $a);
        $b = self::u32($b ^ self::rot($a, 6));
        $a = self::u32($a + $c);
        $c = self::u32($c - $b);
        $c = self::u32($c ^ self::rot($b, 8));
        $b = self::u32($b + $a);
        $a = self::u32($a - $c);
        $a = self::u32($a ^ self::rot($c, 16));
        $c = self::u32($c + $b);
        $b = self::u32($b - $a);
        $b = self::u32($b ^ self::rot($a, 19));
        $a = self::u32($a + $c);
        $c = self::u32($c - $b);
        $c = self::u32($c ^ self::rot($b, 4));
        $b = self::u32($b + $a);
    }

    private static function finalise(int &$a, int &$b, int &$c): void
    {
        $c = self::u32($c ^ $b);
        $c = self::u32($c - self::rot($b, 14));
        $a = self::u32($a ^ $c);
        $a = self::u32($a - self::rot($c, 11));
        $b = self::u32($b ^ $a);
        $b = self::u32($b - self::rot($a, 25));
        $c = self::u32($c ^ $b);
        $c = self::u32($c - self::rot($b, 16));
        $a = self::u32($a ^ $c);
        $a = self::u32($a - self::rot($c, 4));
        $b = self::u32($b ^ $a);
        $b = self::u32($b - self::rot($a, 14));
        $c = self::u32($c ^ $b);
        $c = self::u32($c - self::rot($b, 24));
    }

    private static function rot(int $value, int $distance): int
    {
        $value = self::u32($value);

        return self::u32(($value << $distance) | ($value >> (32 - $distance)));
    }

    private static function u32(int $value): int
    {
        return $value & 0xFFFFFFFF;
    }
}
