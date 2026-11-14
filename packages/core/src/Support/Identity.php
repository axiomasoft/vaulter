<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Attributes\Api;
use Vaulter\Models\Casts\IntegerKey;
use Vaulter\Models\Concerns\HasVaulterKeys;

/**
 * Safely stringifies model keys / auth identifiers (which Eloquent types as mixed).
 */
#[Api]
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
        if (in_array(HasVaulterKeys::class, $traits, true)) {
            $strategy = self::vaulterStrategy($model);
            $bigint = ($model->getCasts()[$model->getKeyName()] ?? null) === IntegerKey::class;

            return match ($strategy) {
                'uuid7' => Str::isUuid((string) $key),
                'ulid' => Str::isUlid((string) $key),
                default => $bigint ? self::numericFitsKey($model, $key, true)
                    : ($model->getKeyType() !== 'int' || self::numericFitsKey($model, $key)),
            };
        }

        return $model->getKeyType() !== 'int' || self::numericFitsKey($model, $key);
    }

    /** Bigint bounds are compared as decimal strings: MySQL's unsigned maximum exceeds PHP_INT_MAX. */
    private static function numericFitsKey(Model $model, int|string $key, bool $vaulterBigint = false): bool
    {
        $number = (string) $key;
        if (preg_match('/^[0-9]+$/D', $number) !== 1) {
            return false;
        }
        $number = ltrim($number, '0');
        // Vaulter's auto-increment strategy creates an unsigned bigint on MySQL/MariaDB. A generic integer model
        // does not establish an unsigned schema. Reading the configured driver does not open PDO or issue SQL.
        $maximum = $vaulterBigint && in_array($model->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? '18446744073709551615' : '9223372036854775807';

        return strlen($number) < strlen($maximum)
            || (strlen($number) === strlen($maximum) && strcmp($number, $maximum) <= 0);
    }

    /** The model's own id strategy, including a panel override of the global one. */
    private static function vaulterStrategy(Model $model): string
    {
        $strategy = (new \ReflectionMethod($model, 'vaulterIdStrategyKey'))->invoke($model);
        if (!is_string($strategy)) {
            throw new \LogicException('A Vaulter model must declare a string id strategy key.');
        }

        return $strategy;
    }
}
