<?php

declare(strict_types=1);

namespace Vaulter;

/**
 * Patch one namespace atomically (PLAN2.D41): the set keys.
 *
 * (F-P1.8-02) Whole line gone.
 *
 * @property int $size_bytes
 *                           Owned by core (P2 #5 / ADR-0019) — see {@see Other}.
 */
final class Sample
{
    /** (§2.2 / D14) */
    public const LITERAL = 'D10 stays; PLAN2.D41 stays // P7.1';

    public function run(): void
    {
        // forceFill (P1.4/F-P1.8-02): server-built array
        // H.3
        $x = 1; // see D10
    }
}
