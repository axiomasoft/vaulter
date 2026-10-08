# P4.8 — completion notes (draft by the code session; the check session appends results)

## What changed

- `Documents::in($drive)` → `Operations\DocumentOperations` (create, content, update, snapshot, revisions,
  restore, setRetention, export, collaborationSession) and `Documents::comments($drive)` →
  `Operations\CommentOperations` (list, add, resolve, delete), through `DriveHandle::extension()`, bound
  non-singleton in `BindingsRegistrar`; facade `@method` docblock.
- Snapshots `Data\Snapshots\{DocumentSnapshot,DocumentRevisionSnapshot,CommentSnapshot}` + internal
  `Data\Snapshots\Mapping\DocumentSnapshotMapper`; `Queries\DocumentQuery` (revisions created_at DESC,id DESC;
  comments oldest first; revision/comment strictly inside the node).
- Actions → `execute(OperationContext, *Input)`; actor from the context; node must be in the context drive
  (sameStorage vs `$context->driveId()` under the locks). `UpdateRevisionRetentionAction` now pins and
  releases, inside a transaction under T → N → D (it used to write outside any transaction).
- Controllers DocumentNodeController / NodeCommentController / NodeCollaborationController::token call only
  Operations; hub endpoints go through internal `Collaboration\CollaborationHub` (no protocol change).
- `CollaborationSessionService::issue(Node, Ability, actorId)` — no access decision of its own.
- Core `Http\Support\TrashGuard` deleted (no consumer).
- New `CommentNotFoundException` (`comment_not_found`, 404).
- Arch: `documents/tests/Arch/OperationsBoundaryTest.php`, `PublicApiReturnTypesTest.php`; drive exempt list
  without the three documents controllers; documents golden extended (facade, manager, operations, snapshots,
  AddCommentInput, CollaborationSession, DocumentExport).
- Tests: `tests/Operations/{DocumentOperationsTest,CommentOperationsTest,DocumentAbilityMatrixTest}.php`
  (+ `OperationsTestCase`, Pest binding); ~90 direct Action call sites rewritten with
  `DocumentTestHelpers::context()`; HTTP expectations changed where the design changed them.
- Docs: guide documents (PHP API section), errors, comments, export, collaboration (+ ru mirrors of
  documents, errors, comments, export); UPGRADE.md; CHANGELOG.md [Unreleased] + changelog-entry.md.

## Actual ability matrix (RoleRegistry v1)

previewer {view}: content, revisions, session(ro), comments.list ✓; update/snapshot/restore/setRetention,
export, comments.add → permission_denied. reader {view, download}: + export. commenter: + comments.add.
writer/owner: everything, session rw. No grant, trashed node, other drive, other tenant → node_not_found on
every method. Reader has Download by default, so the role that cannot export is previewer.

## Invariant self-check (P4-invariants)

- P4-I1/I2/I4: create/update/restore still go through `CreateDocumentAction`/`DocumentStateWriter` only; no new
  writer of v_documents/v_document_revisions (DocumentStateWriterBoundaryTest unchanged).
- P4-I3: retention changes only `keep_forever` (`DocumentRevisionStoreRepository::setRetention`).
- P4-I8: content writes do not touch `v_nodes.sequence` — asserted in DocumentOperationsTest (update + rename).
- P4-I15: export = Download + ContentEgress in Operations, NodeExportService still re-checks Download.
- P4-I16: `DocumentSnapshot::$editable` false for historical revisions / unavailable types (degraded
  content is P4.3).
- P4-I23: PublicApiReturnTypesTest (documents) over DocumentOperations, CommentOperations, DocumentsManager.
- P4-I24: every Action write is inside `VaulterDatabase::transaction()`; retention fixed to be.
- Lock order: Actions keep T(shared) → N → D; retention adds D before reading R; no N after D.
- Pairs: update‖update and restore‖store serialize on D in the writer (unchanged); rename‖update — independent
  validators (tested on SQLite; PG race is P4.7 barrier).

## Deferred

- V4 (PG tenant rows of DocumentAbilityMatrixTest, `--filter=tenant`) → P4.7 barrier V90 (D51).
- collaboration_unavailable / epoch in session → P4.5; registry types → P4.3; ExportDocumentAction → P4.4.

## Check session results

Golden regenerated once: 109 added lines, no removed.
- V1 exit 0 (340 passed, 3 skipped); V1x exit 0 (125 passed); V2 exit 0 (4 passed); V3 exit 0 (172 passed); V3x exit 0 (3 tests, risky/0 assertions = suites' own skips); V80 exit 0 (pint+phpstan core, documents, drive); V81 exit 0 (1438 units, 0 violations). V4 deferred to P4.7 (D51). Receipts: receipts/.
- Fixes (logged as difficulty): CreateDocumentAction inlines rename-on-conflict (documents must not use Vaulter\Drive); MappedCursorPaginator for revisionPage; mapper typeAvailable accepts format label; profile test ordering; PHPStan narrowing fixes in 3 Actions, mapper, 3 controllers (Identity::stringify).
- NC1-NC5 done (negative-controls.md); NC2 needed both Operations and NodeExportService broken.
- grok-4.7 review: one low finding (comment Actions lack lock/sameStorage), not fixed, follow-up; see review-verdict.md.
