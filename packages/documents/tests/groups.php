<?php

declare(strict_types=1);

/*
 * Test groups of the documents suite, paths relative to this directory; see packages/core/tests/groups.php for the
 * format.
 */
return [
    'database' => [
        'Concurrency',
        'AfterCommit/CommentAfterCommitTest.php',
        'Schema',
        'Document/AtomicDocumentMutationTest.php',
        'Document/DocumentHeadIntegrityTest.php',
        'Document/DocumentPurgeLifecycleTest.php',
        'Document/DocumentStateVersionTest.php',
        'Document/DocumentStateWriterTest.php',
        'Document/DocumentTreeInvariantsTest.php',
        'Document/PurgeTrashCascadeTest.php',
        'Document/DocumentSchemaWritableTest.php',
        'Document/DocumentCopyLifecycleTest.php',
        // The marker CHECK and the T → N → D locks of the collaborative-state contract.
        'State/CollaborationBindingTransitionTest.php',
        'State/CollaborativeStateTest.php',
        'Types/UnknownDocumentTypeFallbackTest.php',
    ],
    'serial' => [
        'Concurrency',
    ],
    'extended' => [],
    'stand' => [],
    'engine:server' => [
        'Concurrency',
    ],
];
