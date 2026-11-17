<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Packman\Attributes\Internal;

/**
 * The size of a scale test: the real one when the run is a full/discovery run (`scripts/t` exports VAULTER_FULL_SEEDS=1),
 * a cheap equivalent that exercises the same code path otherwise.
 */
#[Internal]
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
