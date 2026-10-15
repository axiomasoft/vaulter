<?php

declare(strict_types=1);

/*
 * Test groups of the core suite (PLAN3 P1.5), paths relative to this directory; Vaulter\Tests\Support\TestGroups turns them into
 * Pest groups, tests/Repo/TestGroupsTest.php keeps them true. A test belongs to `database` only when its RESULT depends on the
 * engine; why each entry is here: plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
 */
return [
    'database' => [
        'Schema',
        'Concurrency',
        'IdStrategy',
        'TablePrefix',
        'Ulid',
        'HostKeyMysql',
        'Database',
        'Idempotency',
        'Changes/ChangeFeedReadTest.php',
        'Changes/ChangeJournalTest.php',
        'Changes/PruneChangesCommandTest.php',
        'Events/EventRecorderTest.php',
        'Events/WebhookAutoDisableCommitTest.php',
        'Tenancy/TenantProvisioningRaceTest.php',
        'Tenancy/TenantIdentifierPredicateTest.php',
        'Sharing/ShareSecurityBoundaryTest.php',
        'Webhooks/ConcurrentDeliveryTest.php',
        'Outbox/OutboxRelayTest.php',
        'Outbox/OutboxWriteTest.php',
        'Outbox/WebhookFanoutConsumerTest.php',
        'Webhooks/OutboxTransactionTest.php',
        'Webhooks/WebhookSchemaTest.php',
        'Webhooks/SigningSecretEncryptionTest.php',
        'Webhooks/WebhookPruneSafetyTest.php',
        'Webhooks/WebhookRetryDispatcherTest.php',
        'Webhooks/WebhookAutoDisableTest.php',
        'Feature/PruneWebhooksTest.php',
        'Access/AccessAwareListingTest.php',
        'Access/EvaluateManyEquivalenceTest.php',
        'Access/WideNodeListingTest.php',
        'Content/ScanEvidenceBoundaryTest.php',
        'Doctor/CoreDoctorChecksTest.php',
        'Console/InstallCommandTest.php',
        'Unit/DatabaseSchemaReuseTest.php',
    ],
    // Forked children on their own connections, or process-global state a parallel worker would trample.
    'serial' => [
        'Concurrency',
        'Database/VaulterDatabaseTest.php',
        'Sharing/ShareSecurityBoundaryTest.php',
        'Tenancy/TenantProvisioningRaceTest.php',
        'Webhooks/ConcurrentDeliveryTest.php',
    ],
    // No test of this suite qualifies yet: a scale test moves here only with a cheap same-defect equivalent on the PR lane
    // (classification.md, "Extended").
    'extended' => [],
    'stand' => [],
    // Whole files that can only say something on a server engine: skipped before the schema is built on SQLite.
    'engine:server' => [
        'Concurrency',
        'Tenancy/TenantProvisioningRaceTest.php',
    ],
];
