<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use WeakMap;

/**
 * Container-scoped cache for compiled options DTOs that automatically recompiles
 * when the underlying configuration subtree changes (e.g. between tests or hooks).
 */
#[Spi(SpiKind::Call, since: '1.0')]
final class CompiledOptionsCache
{
    /** @var WeakMap<Container, array<string, array{0: mixed, 1: object}>>|null */
    private static ?WeakMap $cache = null;

    /**
     * @template T of object
     *
     * @param  Closure(array<array-key, mixed>): T $compile
     * @return T
     */
    public static function resolve(Container $app, string $configKey, Closure $compile): object
    {
        $cache = self::$cache ??= new WeakMap;

        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get($configKey, []) : [];
        $entries = $cache[$app] ?? [];

        if (isset($entries[$configKey]) && $entries[$configKey][0] === $raw) {
            /** @var T $cached */
            $cached = $entries[$configKey][1];

            return $cached;
        }

        $options = $compile(is_array($raw) ? $raw : ['__invalid_root' => $raw]);
        $entries[$configKey] = [$raw, $options];
        $cache[$app] = $entries;

        return $options;
    }
}
