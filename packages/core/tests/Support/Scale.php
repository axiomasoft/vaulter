<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support;

/**
 * The size of a scale test: the real one when the run is a full/discovery run (`scripts/t` exports VAULTER_FULL_SEEDS=1),
 * a cheap equivalent that exercises the same code path otherwise (PLAN3 P1.11).
 */
final class Scale
{
    public static function full(): bool
    {
        return getenv('VAULTER_FULL_SEEDS') === '1';
    }

    public static function pick(int $full, int $fast): int
    {
        return self::full() ? $full : $fast;
    }
}
