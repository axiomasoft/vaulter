<?php

declare(strict_types=1);

/*
 * Test groups of the azguard suite, paths relative to this directory; see packages/core/tests/groups.php for the
 * format.
 */
return [
    // The real AzGuard storage migration and its DB-backed grants.
    'database' => [
        'RealAzGuard',
        'RealAzGuardIsolated',
    ],
    'serial' => [],
    'extended' => [],
    'stand' => [],
];
