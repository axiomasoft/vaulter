<?php

declare(strict_types=1);

/*
 * Test groups of the media suite, paths relative to this directory; see packages/core/tests/groups.php for the
 * format.
 */
return [
    'database' => [
        'Cas/BlobCommandsTest.php',
        'Cas/BlobCommitAtomicityTest.php',
        'Cas/BlobCommitTest.php',
        'Cas/BlobCrossScopeGcTest.php',
        'Cas/BlobDedupScopeBehaviourTest.php',
        'Cas/BlobDedupScopeTest.php',
        'Cas/BlobFindOrCreateTest.php',
        'Cas/BlobGarbageCollectorTest.php',
        'Cas/BlobGcBatchTest.php',
        'Cas/BlobGcCriteriaTest.php',
        'Cas/BlobGcPipelineTest.php',
        'Cas/BlobOrphanPruneTest.php',
        'Cas/BlobOrphanSweepTest.php',
        'Cas/BlobPinGcTest.php',
        'Cas/BlobPinStatusTest.php',
        'Cas/BlobPinTransactionTest.php',
        'Cas/BlobReconcilerTest.php',
        'Cas/BlobScopeKeyTest.php',
        'Cas/BlobScrubHealTest.php',
        'Cas/BlobScrubTest.php',
        'Cas/CasPromoteAtomicTest.php',
        'Cas/ColdMutationSurvivorTest.php',
        'Cas/CommandOutputContractTest.php',
        'Cas/MutationContractsTest.php',
        'Cas/PinSweepRaceTest.php',
        'Cas/ReconcileBlobsOrphanCommandTest.php',
        'Cas/ScopeKeyReconcileTest.php',
        'Cas/SweepPendingSerializableTest.php',
    ],
    'serial' => [
        'Cas/PinSweepRaceTest.php',
        'Cas/ScopeKeyReconcileTest.php',
    ],
    'extended' => [],
    'stand' => [],
    'engine:server' => [
        'Cas/ScopeKeyReconcileTest.php',
    ],
    'engine:pgsql' => [
        'Cas/PinSweepRaceTest.php',
        'Cas/SweepPendingSerializableTest.php',
    ],
];
