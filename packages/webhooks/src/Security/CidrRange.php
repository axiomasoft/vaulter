<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Security;

use Packman\Attributes\Internal;

/** The one strict CIDR parser shared by config validation, the production invariant and the runtime guard. */
#[Internal]
final class CidrRange
{
    /** @return array{string, int}|null packed network address and prefix length; null when malformed (never coerced). */
    public static function parse(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $bin = @inet_pton($parts[0]);

        if ($bin === false) {
            return null;
        }

        $max = strlen($bin) * 8;

        if (!isset($parts[1])) {
            return [$bin, $max];
        }

        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
            return null;
        }

        return [$bin, (int) $parts[1]];
    }

    /** True when the packed address lies inside the parsed range of the same family. */
    public static function contains(string $addressBin, string $networkBin, int $bits): bool
    {
        return strlen($addressBin) === strlen($networkBin) && self::prefixMatches($addressBin, $networkBin, $bits);
    }

    public static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);

        if ($full > 0 && strncmp($a, $b, $full) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || ((ord($a[$full]) ^ ord($b[$full])) & ((0xFF << (8 - $rest)) & 0xFF)) === 0;
    }
}
