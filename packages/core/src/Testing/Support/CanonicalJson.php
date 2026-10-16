<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Vaulter\Attributes\Internal;

/**
 * A JSON value in one key order. MySQL normalises a JSON column and returns object keys sorted (shortest first), so a
 * payload read back is compared by content and types, never by the order its keys were written in. Lists keep their order.
 */
#[Internal]
final class CanonicalJson
{
    /** @return mixed the same value with every object's keys sorted, recursively */
    public static function of(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::of(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
