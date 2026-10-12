**FINDINGS.** One plausible major defect. Nothing else in the six seams reproduced on SQLite.

## 1. major (plausible) — MySQL repeatable-read snapshot disagrees with the locking journal read

`packages/core/src/Operations/ChangeOperations.php:90-96` and `:126`, then `packages/core/src/Operations/NodeLocator.php:58` and `packages/core/src/Access/OperationGuard.php:125-141`.

Inside an open transaction the head and the journal page are read `FOR SHARE`, so on MySQL they return the latest committed rows. The node load, the ACL evaluation, and the snapshot are ordinary `SELECT`s. Under MySQL’s default `REPEATABLE READ` those keep the snapshot from the transaction’s first read. A locking read does not refresh that snapshot ([consistent nonlocking reads](https://docs.oracle.com/cd/E17952_01/mysql-8.0-en/innodb-consistent-read.html), [locking reads](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/innodb-locking-reads.html)). There is no post-check on this path.

Failure: a host transaction has already read. Another session then commits a journalled change. `list()` sees the new journal row and advances the cursor past it, but projects it with the old node and the old grants.

- File moved into a folder the reader cannot see: the page is an upsert of the old snapshot, not `remove(moved_out)`. The client keeps a node it can no longer see.
- Grant just given: the ACL snapshot does not contain the grant, so `grant.created` becomes `remove(permission_lost)` or is skipped. The client misses a node it can now see.

The cursor is already past that seq, so the next page does not repair it.

Reproduction was not executed. PostgreSQL and MySQL were out of scope. `ChangeJournalRaceTest.php:150` only checks that `FOR SHARE` sees a journal row inserted after the snapshot, and it uses node ids that do not exist, so the stale node read never runs. On SQLite, `FOR SHARE` is a no-op and every statement in the transaction shares one snapshot, so head, rows, and nodes stay aligned. PostgreSQL’s default `READ COMMITTED` also takes a fresh snapshot per statement.

## Checked, not reported

Seq allocation holds the head row until commit, and every journalled action records after its other locks. Compaction matches D61: a plain upsert does not delete a `node.moved` / `grant.*` / `access.inheritance_changed` row, and a remove is never compacted. A throwaway probe (removed from `/tmp`) confirmed a folder moved out of view stays `remove(moved_out)` after a child rename, and the child is not returned. Cursor verification uses `hash_equals`, rejects non-int epoch/position and foreign drive/tenant. Prune raises `pruned_through` in the same transaction as the delete and never lowers it. Schema ids go through `IdStrategy::reference` for bigint, ULID, and UUID7; backfill copies `tenant_id` and is idempotent. The collaboration hub signature matches `timestamp + '.' + rawBody` in `hocuspocus-server/src/webhook.js`.

SQLite: `packages/core/tests/Changes` 49 passed, `packages/drive/tests/Changes/DriveChangesEndToEndTest.php` 2 passed, `packages/documents/tests/Events/DocumentJournalTest.php` 3 passed.