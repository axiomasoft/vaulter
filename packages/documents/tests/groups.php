<?php

declare(strict_types=1);

/*
 * Test groups of the documents suite (PLAN3 P1.5), paths relative to this directory; see packages/core/tests/groups.php for the
 * format and plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md for the reason of each entry.
 */
return [
    'database' => [
        'Concurrency',
        'AfterCommit/CommentAfterCommitTest.php',
        'Schema',
        'Document/DocumentHeadIntegrityTest.php',
        'Document/DocumentPurgeLifecycleTest.php',
        'Document/DocumentStateVersionTest.php',
        'Document/DocumentStateWriterTest.php',
        'Document/DocumentTreeInvariantsTest.php',
        'Document/PurgeTrashCascadeTest.php',
        'Document/DocumentSchemaWritableTest.php',
        'Document/DocumentCopyLifecycleTest.php',
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
