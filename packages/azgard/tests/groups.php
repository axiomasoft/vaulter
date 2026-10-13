<?php

declare(strict_types=1);

/*
 * Test groups of the azgard suite (PLAN3 P1.5), paths relative to this directory; see packages/core/tests/groups.php for the
 * format and plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md for the reason of each entry.
 */
return [
    // The real AzGuard storage migration and its DB-backed grants.
    'database' => [
        'RealAzgard',
        'RealAzgardIsolated',
    ],
    'serial' => [],
    'extended' => [],
    'stand' => [],
];
