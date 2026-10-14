<?php

declare(strict_types=1);

/*
 * Test groups of the drive suite (PLAN3 P1.5), paths relative to this directory; see packages/core/tests/groups.php for the
 * format and plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md for the reason of each entry.
 */
return [
    'database' => [
        'Concurrency',
        'Changes/DriveChangesEndToEndTest.php',
        'Uploads/UploadSchemaTest.php',
        'Uploads/UploadSessionLockDriverTest.php',
        'Uploads/UploadSessionCriticalSectionTest.php',
        'Uploads/R07bSessionContractsTest.php',
        'Uploads/R07SessionManagerContractsTest.php',
        'Uploads/P3/CompleteUploadIdempotencyTest.php',
        'Uploads/P3/ScanWorkRecoveryTest.php',
        'Uploads/P3/UnifiedIngestTest.php',
        'Feature/PurgeTrashTest.php',
        'Feature/TreeInvariantsTest.php',
        'Feature/ExactListingTest.php',
        'Feature/R07TusControllerContractsTest.php',
        'Feature/QuotaReserveTest.php',
        'Drive/DrivePurgeTest.php',
        'PublicApi/LifecycleBoundaryTest.php',
        'PublicApi/NodeSequenceContractTest.php',
    ],
    // Every forked race of the Concurrency folder (CompleteUploadRetryTest does not fork: it stays in the parallel lane).
    'serial' => [
        'Concurrency/CompleteUploadRaceTest.php',
        'Concurrency/DedicatedConnectionTest.php',
        'Concurrency/DriveArchiveRaceTest.php',
        'Concurrency/LockOrderRaceTest.php',
        'Concurrency/MoveCycleRaceTest.php',
        'Concurrency/NodeSequenceBoundaryTest.php',
        'Concurrency/OutermostRetryRealFailureTest.php',
        'Concurrency/QuotaRaceTest.php',
        'Concurrency/QuotaRecountRaceTest.php',
        'Concurrency/RevisionNumberRaceTest.php',
        'Concurrency/RevisionPruneRaceTest.php',
        'Concurrency/SameNameUploadRaceTest.php',
        'Concurrency/ScanWorkRecoveryRaceTest.php',
        'Concurrency/TreeLockRaceTest.php',
        'Concurrency/TusConcurrentPatchTest.php',
        'Concurrency/TusPatchVsSweepRaceTest.php',
    ],
    'extended' => [],
    'stand' => [],
    // Files whose every test skips on SQLite (verified by the skip counts of P1.5): no schema is built for them there.
    'engine:server' => [
        'Concurrency/CompleteUploadRaceTest.php',
        'Concurrency/DedicatedConnectionTest.php',
        'Concurrency/DriveArchiveRaceTest.php',
        'Concurrency/LockOrderRaceTest.php',
        'Concurrency/MoveCycleRaceTest.php',
        'Concurrency/NodeSequenceBoundaryTest.php',
        'Concurrency/OutermostRetryRealFailureTest.php',
        'Concurrency/QuotaRaceTest.php',
        'Concurrency/QuotaRecountRaceTest.php',
        'Concurrency/RevisionNumberRaceTest.php',
        'Concurrency/RevisionPruneRaceTest.php',
        'Concurrency/RootTransactionSnapshotTest.php',
        'Concurrency/SameNameUploadRaceTest.php',
        'Concurrency/ScanWorkRecoveryRaceTest.php',
        'Concurrency/TreeLockRaceTest.php',
        'Concurrency/TusPatchVsSweepRaceTest.php',
    ],
];
