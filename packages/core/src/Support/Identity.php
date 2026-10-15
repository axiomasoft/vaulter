<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    /**
     * Whether an untrusted key can name a row of the model at all. A key the column cannot hold is "not found": it
     * must not reach the query, where PostgreSQL fails the cast (bigint, uuid) and the request ends in a 500.
     */
    public static function fitsKey(Model $model, int|string $key): bool
    {
        $traits = class_uses_recursive($model);
        if (in_array(HasUuids::class, $traits, true)) {
            return Str::isUuid((string) $key);
        }
        if (in_array(HasUlids::class, $traits, true)) {
            return Str::isUlid((string) $key);
        }

        return $model->getKeyType() !== 'int' || preg_match('/^[0-9]{1,19}$/', (string) $key) === 1;
    }
}
