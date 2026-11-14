<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Vaulter\Attributes\Internal;

/**
 * A configured list of strings. A non-array falls back to the shipped default.
 * Entries that are not strings are dropped. The order of the strings that remain is kept.
 */
#[Internal]
final class StringList
{
    /**
     * @param  list<string> $default
     * @return list<string>
     */
    public static function from(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
