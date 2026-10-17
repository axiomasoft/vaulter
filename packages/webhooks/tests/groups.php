<?php

declare(strict_types=1);

/*
 * Test groups of the webhooks suite (PLAN3 P4.13), paths relative to this directory; see packages/core/tests/groups.php for the
 * format. These entries moved here together with the tests from the core groups.php (P1.5 classification), the reason of each:
 * plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
 */
return [
    'database' => [
        'Concurrency',
        'Events/WebhookAutoDisableCommitTest.php',
        'Feature/PruneWebhooksTest.php',
        'Outbox/WebhookFanoutConsumerTest.php',
        'Webhooks/ConcurrentDeliveryTest.php',
        'Webhooks/OutboxTransactionTest.php',
        'Webhooks/SigningSecretEncryptionTest.php',
        'Webhooks/WebhookAutoDisableTest.php',
        'Webhooks/WebhookPruneSafetyTest.php',
        'Webhooks/WebhookRetryDispatcherTest.php',
        'Webhooks/WebhookSchemaTest.php',
    ],
    // Forked children on their own connections, or process-global state a parallel worker would trample.
    'serial' => [
        'Concurrency',
        'Webhooks/ConcurrentDeliveryTest.php',
    ],
    'extended' => [],
    'stand' => [],
    // Whole files that can only say something on a server engine: skipped before the schema is built on SQLite.
    'engine:server' => [
        'Concurrency',
    ],
];
