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
        // The update log, receipts and heads (P5.4): binary payloads, composite keys and the FOR UPDATE head lock.
        'Persistence',
        // Compaction, drain, closing and prune of generations (P5.5): the same tables, timestamps of leases and retirement.
        'Restore',
        'Console/PruneGenerationsTest.php',
        // Managed and raw disable (P5.6): the drain over the same tables, and the uninstall that rolls them back.
        'Disable',
        // Revocation (P5.10): the permission versions table, the outbox rows of the control consumer.
        'Revocation',
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
