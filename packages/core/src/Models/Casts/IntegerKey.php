<?php

declare(strict_types=1);

namespace Vaulter\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Packman\Attributes\Internal;

/** @implements CastsAttributes<int|string, int|string> */
#[Internal]
final class IntegerKey implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): int|string|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('An integer key must be an integer or a decimal string.');
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        if ($digits === '') {
            return 0;
        }
        $maximum = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        $canonical = $negative ? '-'.$digits : $digits;

        return strlen($digits) < strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0)
            ? (int) $canonical : $canonical;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): int|string|null
    {
        return $value;
    }
}
