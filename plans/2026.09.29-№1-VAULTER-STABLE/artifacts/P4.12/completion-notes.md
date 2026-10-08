# P4.12 — completion notes (executor claude/claude-opus-5-5/high)

## Delivered
- core: `Profiles\RevisionRetentionPolicy` (+ `DrivePolicy::$revisionRetention`, `DrivePolicyBuilder::revisionRetention()`, `Profile::revisionLimit(n)` → `maxNoncurrentRevisions = n−1`); `Quota\QuotaCalculator` (@internal: usedBytes, trashedBytes, usedBytesByDrive); `v_file_revisions.superseded_at` (SchemaBuilder + migration `2026_10_07_000007_quota_all_revisions`: add column, backfill from the next revision's committed_at, recount used_bytes in batches, guarded, down() back to the head formula); `FileRevisionStoreRepository::markSuperseded` stamps superseded_at, `setKeepForever`, `delete` (nulls restored_from first); `CannotDeleteHeadRevisionException` (409), `RevisionPinnedException` (409 `revision_pinned`); `FileOperations::deleteRevision/setRevisionRetention`; `BlobScope` without `global()` for uploads, `unshared()` for dedup off, null tenant → drive.
- drive: `DeleteFileRevisionAction` (T shared → N → F → R → Q → C, head/pin refused under lock, −size, edges detached, `FileRevisionDeleted`), `SetFileRevisionRetentionAction`, `Jobs\PruneFileRevisionsJob` (system actor `revision-retention`, files in batches of 500, unique per drive), `vaulter:revisions:prune {--drive=*} {--dry-run}`; quota by P4-design#Revisions on every path: Ingest (+full size), Restore (+full size, restored_from kept), Copy (+head, unchanged), Delete/Prune (−size), Purge (−Σ all revisions via `FileContentLifecycle::preparePurge`); dead `Files\FileRevisionRetention` removed; owning correction of `RescanFileRevisionsCommand --activate-policy`.
- media: `Configuration\DedupScope` (tenant, drive, off); `MediaConfig::dedupScope(): DedupScope`, 'global'/unknown → `InvalidConfigurationException` (checked in packageBooted); repair-scopes handles `global` like legacy:snull/tnull; reconciler grammar accepts `o-` segments.
- docs (en): guide/uploads (quota + Revision retention), guide/storage-and-blobs, guide/configuration, guide/errors, reference/commands; UPGRADE.md and CHANGELOG.md [Unreleased].

## P4-I self-check
- P4-I18 (used_bytes = Σ retained revisions, no dedup discount): conditional UPDATE on every add/remove path under Q after F/R; QuotaLedgerTest asserts used_bytes == QuotaCalculator after every step (upload, revision, trash, restore node, restoreRevision, deleteRevision, purge, copy, subtree purge, identical uploads = 2×size); QuotaAllRevisionsUpgradeTest proves the migration recount; NC3/NC4 red when broken.
- P4-I19 (head and pinned never deleted): DeleteFileRevisionAction re-reads head and keep_forever under F and R locks; prune selects without locks and skips refusals; RevisionRetentionTest + QuotaLedgerTest, NC1/NC2; RevisionPruneRaceTest (PG) pins and adds a head between choice and delete.
- Lock order (P4-invariants LockOrder): delete/pin T(shared) → N → F → R → Q → C; ingest/restore/copy/purge orders unchanged (reservation still after F/R).
- P4-I23: new public methods return FileRevision/void (no Eloquent collections); snapshot DTOs are P4.13.
- P4-I24 (WriteScope) is P4.13; all new writes run inside VaulterDatabase::transaction.
- D49: no upload path builds 'global'; boot refuses the setting; repair-scopes moves existing global blobs (PG test RepairBlobScopesCommandTest, skipped on SQLite).

## Checks run by the executor (SQLite, focused)
- drive: tests/Drive/{QuotaLedgerTest,RevisionRetentionTest,ForceDeleteTest,TrashRestoreTest,UploadFileTest,CopyMutationContractsTest,R07CommandActivityContractsTest}, tests/PublicApi/{OperationMatrixTest,LifecycleBoundaryTest}, tests/Feature/{R07bActionNodeContractsTest,QuotaReserveTest,R07ActionControllerContractsTest,TreeInvariantsTest,R07bRequestCommandContractsTest}, tests/Uploads/{TusFlowTest,S3MultipartTest,P3/ScanWorkRecoveryTest}, tests/Profiles/ProfileRuntimeTest, tests/Configuration/ModuleRegistrationOrderTest, tests/Arch — all green.
- media: tests/Feature, tests/Configuration, tests/Arch, tests/Cas/{BlobDedupScopeBehaviourTest,BlobScopeKeyTest,BlobCommitAtomicityTest,BlobReconcilerTest,BlobOrphanSweepTest,BlobGcBatchTest} — green.
- core: tests/Unit/BlobScopeTest, tests/Upgrade/QuotaAllRevisionsUpgradeTest, tests/Profiles, tests/Schema, tests/Arch/PublicApiSnapshotTest — green.
- V80: pint --test core/drive/media exit 0; phpstan core (phpstan.neon) 0 errors, drive 0 errors (after the RescanFileRevisionsCommand correction), media 0 errors.
- V81: opus-coverage OK (1438 units, 0 violations).
- negative controls: artifacts/P4.12/negative-controls.md (NC1–NC7).

## Not run by the executor (handed to the Sonnet barrier session)
V1 and V2 full directories, V3/V4 (PG), V90 (P4.10 V3 on PG + MySQL schema), extra affected PG files (drive tests/Concurrency/{NodeSequenceBoundaryTest,LockOrderRaceTest,QuotaRaceTest,CompleteUploadRaceTest}, media tests/Cas/RepairBlobScopesCommandTest), RU doc mirrors.
