# P4.2 — completion notes

Executor: anthropic/claude-sonnet-5-5/high. Mode D51 L (SQLite only); V4 (PG) is kind=impact and runs in the P4.7 barrier V90.

## Result
- core: `registerFallback()`, `DuplicateNodeContentLifecycleException` (`duplicate_registration`), `PurgeOnlyContentLifecycle`; `assertCopySupported` lost the Document/Shortcut branch and refuses a kind whose resolved lifecycle is purge-only.
- drive: `DocumentPurgeLifecycle` is a fallback, both it and `ShortcutPurgeLifecycle` carry the marker; the registry factory also collects the tag `vaulter.content_lifecycles`.
- documents: `DocumentContentLifecycle` (copy through `DocumentStateWriter` cause copy + forceCheckpoint, purge by 500-row batches then the row), registered under the tag.
- Docs: docs/guide/documents.md + ru mirror, UPGRADE.md, artifacts/P4.2/changelog-entry.md.

## Checks
V1 exit 0 (6 passed, 1 skipped: PurgeTrashCascadeTest is PostgreSQL-only), V2 exit 0 (10 passed), V3 exit 0 (99 passed), V80 pint + phpstan core/documents/drive clean, V81 ok. Negative controls: artifacts/P4.2/negative-controls.md.

## Deviations
- `core|documents` `PublicApiSnapshotTest` needed no change: the golden files do not list the new `@internal` classes, the exception or the registry method.
- Two tests outside the listed task_files had to change with the behaviour: drive `tests/PublicApi/LifecycleBoundaryTest.php` (duplicate registration now `DuplicateNodeContentLifecycleException`) and documents `tests/Arch/DocumentStateWriterBoundaryTest.php` (the copy lifecycle creates the aggregate through `DocumentStoreRepository`, like `CreateDocumentAction`).
- D30 regression runs in drive without the documents package, so its fixture is a hidden `kind=document` node; the refusal happens before the action, hence no `v_documents`/`v_document_revisions` rows.

## Self-check P4-I*
- Lock order T→N→(D|F): copy takes the source document row FOR SHARE after CopyNodeAction holds T and N; purge takes the document FOR UPDATE under the same locks. No R/Q/C taken here.
- Invariants touched: copy yields epoch 0/state_version 1/one revision (V33); the source row is unchanged; quota untouched (inline content, I7). Copy runs only for nodes of `OperationGuard::copyableSubtree` (D30) and supportability of the whole subtree is checked before any insert.
- Not covered here: PG FK RESTRICT/BIGINT copy run (V4 → P4.7); comments are intentionally not copied.
