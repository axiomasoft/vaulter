<?php

namespace Vaulter;

/**
 * Patch one namespace atomically: the set keys.
 *
 * Whole line gone.
 * @property int    $size_bytes
 * Owned by core (docs/adr/0019-kernel-ownership.md) — see {@see Other}.
 */
final class Sample
{
    public const LITERAL = 'D10 stays; PLAN2.D41 stays // P7.1';

    public function run(): void
    {
        // forceFill: server-built array
        $x = 1;
    }
}
