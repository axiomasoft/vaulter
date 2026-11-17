<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Packman\Attributes\Internal;
use ReflectionEnumUnitCase;
use UnitEnum;

/**
 * Resolves a single PHP attribute declared on an enum case, with per-instance
 * caching (D-none): the cache is owned by this instance, not the
 * process/class — the container binds it as a singleton
 * ({@see VaulterServiceProvider::packageRegistered()}), so its lifecycle
 * follows the container (one per request/job on Octane/queue workers via
 * `flush()`ing between them if ever needed), never the whole PHP process. Callers
 * resolve it through the container (`app(EnumCaseAttributeResolver::class)`), the
 * same pattern already used by {@see ProfileRegistry} and
 * {@see IdStrategyResolver} elsewhere in this package.
 */
#[Internal]
final class EnumCaseAttributeResolver
{
    /**
     * @var array<string, object|null>
     */
    private array $cache = [];

    /**
     * @template T of object
     *
     * @param  class-string<T> $attribute
     * @return T|null
     */
    public function forCase(UnitEnum $case, string $attribute): ?object
    {
        $key = $case::class.'::'.$case->name.'#'.$attribute;

        if (!array_key_exists($key, $this->cache)) {
            $reflection = new ReflectionEnumUnitCase($case::class, $case->name);
            $attributes = $reflection->getAttributes($attribute);

            $this->cache[$key] = $attributes === []
                ? null
                : $attributes[0]->newInstance();
        }

        /** @var T|null $resolved */
        $resolved = $this->cache[$key];

        return $resolved;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
