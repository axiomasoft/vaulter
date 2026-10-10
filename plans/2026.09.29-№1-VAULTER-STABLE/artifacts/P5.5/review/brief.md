# Read-only review — PLAN2.P5.5 drive change journal (vaulter)

You are a fresh, independent, READ-ONLY reviewer. Do not modify any file in the repository. Owner
constraints: NO mutation tools (Infection, pest --mutate), NO long runs (no full suites, no PostgreSQL/MySQL,
nothing longer than about two minutes). You may run targeted SQLite Pest files, e.g.
`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci <file>` from
the repo root (core) or `../../scripts/php84 vendor/bin/pest --ci <file>` from packages/drive or packages/documents.
To prove a finding you may write a throwaway test ONLY under /tmp and run it by path.

## What changed (uncommitted working tree; see `git status` / `git diff`, ignore plans/ views)

A per-drive change journal (sync API like Dropbox list_folder/continue, Graph delta):
- Schema: `packages/core/src/Schema/SchemaBuilder.php` (defineDriveChangeHeads, defineDriveChanges,
  addDriveChanges) + migration `packages/core/database/migrations/2026_10_10_000004_drive_changes.php`
  (creates tables, backfills one head per existing drive).
- Write path: `packages/core/src/Changes/ChangeJournal.php` (append: dedupe by change_id, UPDATE head
  last_seq+1 = journal lock J held until commit, compaction, insert),
  `packages/core/src/Repositories/Changes/*`, `packages/core/src/Events/EventRecorder.php` (journal sink
  between activity and outbox; envelope `changes.seq`), `DomainEvent::journal()` + `journal()` on the
  journalled events in packages/{core,drive,documents}/src/Events/**.
- Read path: `packages/core/src/Operations/ChangeOperations.php` (startCursor, list: head read FOR SHARE in a
  transaction / post-check outside one; projection by current visibility), `packages/core/src/Changes/ChangeCursor.php`
  (HMAC-signed opaque cursor), exceptions in `packages/core/src/Exceptions/Changes/`.
- Retention: `packages/core/src/Console/{PruneChangesCommand,ResetChangesCommand}.php`.
- Wiring: `DriveHandle::changes()` (core contract + packages/drive/src/DriveHandle.php), `DriveProvisioner`
  creates the head, `PurgeDriveStepAction` deletes journal rows; `ChangesConfig` + config keys.
- Side correction: `packages/documents/src/Collaboration/CollaborationCallResolver.php` hub signature.
- Tests: packages/core/tests/Changes/*, packages/core/tests/Concurrency/ChangeJournalRaceTest.php (PG/MySQL only),
  packages/core/tests/Upgrade/DriveChangesUpgradeTest.php (PG only), packages/drive/tests/Changes/DriveChangesEndToEndTest.php,
  packages/documents/tests/Events/DocumentJournalTest.php.

## Normative contract (read these)

- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P5-design-journal.json` (sections Journal, ChangeFeed)
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P5-invariants.json` (LockOrder, P5-I14..I18, Pairs, Bypass)
- `plans/2026.09.29-№1-VAULTER-STABLE/decisions/D59.json` and `D61.json` (D61 strengthens compaction step 3:
  a plain upsert without subtree does not delete an earlier node.moved / grant.* / access.* upsert).

## Risky seams to check

1. Seq ordering and gaps (P5-I14): is J really the last lock in every Action that records a journalled
   event? Any path where seq could be visible before an earlier seq commits, or a rolled-back seq leaks?
2. Read vs prune (P5-I15): post-check outside a transaction, FOR SHARE + locked entry read inside one; MySQL
   REPEATABLE READ snapshot effects; cursor advance to head.last_seq when hasMore=false.
3. Compaction (P5-I16 + D61): can any sequence of events make a reader keep a node it can no longer see, or
   miss a node it can now see? Folders vs files, trash/restore, copy, grants, inheritance, document saves.
4. Visibility (P5-I17): can an upsert ever carry a snapshot of a node the reader may not see (trash, tenant,
   profile policy, purged node, archived/deleting drive)? Do removes leak anything beyond a node id?
5. Cursor (P5-I18): forgery, cross-drive/tenant reuse, type confusion in JSON, timing-safe compare.
6. Migration/backfill: idempotency, id strategies (bigint/ulid/uuid7), tenant copy, down().
7. Prune/reset commands: batch boundary correctness, pruned_through monotonic, lock order (J last).

## Output

Return a verdict `CLEAN` or `FINDINGS`. For each finding: severity (blocker/major/minor), file:line, the
failure scenario, and a REPRODUCTION (a failing test under /tmp with its command and output, or exact command
output). A finding without a reproduction is at most "plausible". Stop after reporting; do not fix anything.
