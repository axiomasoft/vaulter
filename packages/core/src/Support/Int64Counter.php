<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Exceptions\Nodes\SequenceOverflowException;

/**
 * The shared overflow contract of Vaulter's signed BIGINT counters (node sequence,
 * document state version, collaboration epoch): 0..PHP_INT_MAX, never negative,
 * never rolled over — wrapping would make an old validator match again.
 *
 * @internal
 */
final class Int64Counter
{
    public static function increment(int $current): int
    {
        if ($current < 0 || $current === PHP_INT_MAX) {
            throw new SequenceOverflowException;
        }

        return $current + 1;
    }
}
