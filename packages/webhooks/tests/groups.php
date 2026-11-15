<?php

declare(strict_types=1);

/*
 * Test groups of the webhooks suite, paths relative to this directory; see packages/core/tests/groups.php for the
 * format. These entries moved here together with the tests from the core groups.php.
 */
return [
    'database' => [
        'Concurrency',
        'Events/WebhookAutoDisableCommitTest.php',
        'Feature/PruneWebhooksTest.php',
        'Outbox/WebhookFanoutConsumerTest.php',
        'Schema/WebhooksKeyColumnsTest.php',
        'Webhooks/ConcurrentDeliveryTest.php',
        'Webhooks/OutboxTransactionTest.php',
        'Webhooks/SigningSecretEncryptionTest.php',
        'Webhooks/WebhookAutoDisableTest.php',
        'Webhooks/WebhookEndpointCrudTest.php',
        'Webhooks/WebhookEndpointScopeTest.php',
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
