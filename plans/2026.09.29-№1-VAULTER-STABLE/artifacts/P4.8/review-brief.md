You review the UNCOMMITTED working tree of /home/vostrikov/projects/packages/vaulter against HEAD (`git diff HEAD`, plus untracked files from `git status --short packages docs`) for plan item PLAN2.P4.8 "Documents Operations: authorized public API of documents and comments". You are read-only: report findings, change nothing.

KEEP IT SHORT (owner instruction: no long passes). Budget: read only the files listed below and the diff; at most ~25 tool calls; do not explore the rest of the repository; stop and answer as soon as the axes are covered.

DO NOT RUN ANYTHING that executes PHP, Pest, PHPStan, Pint, artisan, composer, npm or the test suites. Read code and `git diff` only (read-only file/grep tools, git read commands). Because you cannot run anything, give each finding the exact code path (file:line chain) that demonstrates it; the executor confirms or rejects it by reading and by its own test run.

Normative spec (read with python3 json, only these sections): plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P4-design.json sections "Operations" and "Snapshots"; recorded deviations: `grep '"P4.8"' plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl`.

Files in scope:
- packages/documents/src/Operations/{DocumentOperations,CommentOperations}.php
- packages/documents/src/Queries/DocumentQuery.php, src/Data/Snapshots/**, src/Collaboration/{CollaborationSessionService,CollaborationHub}.php
- packages/documents/src/Actions/{Documents,Revisions,Comments}/*.php
- packages/documents/src/Http/Controllers/**, src/Http/Resources/*.php, src/Http/Requests/StoreNodeCommentRequest.php
- packages/documents/src/Export/NodeExportService.php, src/DocumentsManager.php, src/Providers/BindingsRegistrar.php
- packages/documents/tests/Operations/*.php, tests/Arch/{OperationsBoundaryTest,PublicApiReturnTypesTest}.php
- packages/drive/tests/Arch/OperationsBoundaryTest.php; deleted packages/core/src/Http/Support/TrashGuard.php

Review axes (real defects only, not style):
1. Authorization: every DocumentOperations/CommentOperations method locates the node through NodeLocator (drive-scoped, active, tenant) BEFORE any other lookup, authorizes the documented ability through OperationGuard with the right OperationKind (View/ContentEgress for content, Edit/Mutation for writes, Download/ContentEgress for export), and only then reveals kind or revision/comment existence. Look for an ability that is too weak, a lookup that escapes the node (revision/comment of another node or drive), or a hidden node that answers 403 instead of 404.
2. Comment rules: resolve = Comment AND (author OR Edit); delete = author OR Manage; author identity compares morph type AND id of the context actor; a system actor is never an author.
3. Actions: the node must belong to the context drive (sameStorage against $context->driveId()); actor taken only from the context; every write inside VaulterDatabase::transaction under T -> N -> D order (UpdateRevisionRetentionAction now locks).
4. HTTP parity: controllers call only Operations, keep URLs and JSON keys; DocumentRevisionResource / NodeCommentResource produce the same keys as before (ids now strings by design D38).
5. Public contract leaks: Eloquent models, builders, collections or paginators reachable from non-@internal public methods.

Return: a JSON list of findings {file, line, severity(high|medium|low), summary, code_path, verdict(PLAUSIBLE|CONFIRMED_BY_READING)} and a one-line overall verdict.
