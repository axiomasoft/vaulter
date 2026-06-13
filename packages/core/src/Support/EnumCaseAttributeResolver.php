<?php

declare(strict_types=1);

namespace DocVault\Support;

use ReflectionEnumUnitCase;
use UnitEnum;

/**
 * Resolves a single PHP attribute declared on an enum case, with per-process caching.
 */
final class EnumCaseAttributeResolver
{
    /**
     * @var array<string, object|null>
     */
    private static array $cache = [];

    /**
     * @template T of object
     *
     * @param  class-string<T> $attribute
     * @return T|null
     */
    public static function forCase(UnitEnum $case, string $attribute): ?object
    {
        $key = $case::class.'::'.$case->name.'#'.$attribute;

        if (!array_key_exists($key, self::$cache)) {
            $reflection = new ReflectionEnumUnitCase($case::class, $case->name);
            $attributes = $reflection->getAttributes($attribute);

            self::$cache[$key] = $attributes === []
                ? null
                : $attributes[0]->newInstance();
        }

        /** @var T|null $resolved */
        $resolved = self::$cache[$key];

        return $resolved;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
