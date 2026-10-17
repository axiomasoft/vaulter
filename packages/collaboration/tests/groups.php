<?php

declare(strict_types=1);

/*
 * Test groups of the collaboration suite (PLAN3 P5.1), paths relative to this directory; see packages/core/tests/groups.php for the
 * format. These entries moved here together with the tests from the documents groups.php (P1.5 classification), the reason of each:
 * plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
 */
return [
    'database' => [
        'Concurrency',
        'Collaboration/CollaborationWebhookTest.php',
        'Collaboration/HubProtocolTest.php',
    ],
    // Forked children on their own connections, or process-global state a parallel worker would trample.
    'serial' => [
        'Concurrency',
    ],
    'extended' => [],
    'stand' => [],
    // Whole files that can only say something on a server engine: skipped before the schema is built on SQLite.
    'engine:server' => [
        'Concurrency',
    ],
];
