<?php

declare(strict_types=1);

namespace DocVault\Support;

/**
 * Safely stringifies model keys / auth identifiers (which Eloquent types as mixed).
 */
final class Identity
{
    public static function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
