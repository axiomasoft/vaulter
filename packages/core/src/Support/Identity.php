<?php

declare(strict_types=1);

namespace Vaulter\Support;

/**
 * Safely stringifies model keys / auth identifiers (which Eloquent types as mixed).
 */
final class Identity
{
    public static function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalise a model key / actor identifier (Eloquent types these mixed) to the
     * canonical int|string|null shape Vaulter columns and DTOs carry.
     */
    public static function key(mixed $value): int|string|null
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }
}
