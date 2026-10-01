# P0.3 — Completion Notes (partial: PostgreSQL done, MySQL blocked)

Run `daafecd1422d695c7ec54686820fe1f5d9428f65b361bd2f098ba8492c247af9`, route claude-opus-5-5/high (`over`).
Tag `v0.2.0` = `983c345c1088363a1f52c6b44096f4e82c6a2cd4`; worktree `build/fixtures/v0.2.0` (not committed).
PostgreSQL 16.15 (`pg_dump` 16.15), MySQL 8.0.46 (`db-preflight.sh mysql` exit 0 — O-2 satisfied).

| Validation | Result | Evidence |
|:--|:--|:--|
| `make-v0.2-dump.sh --verify` (PG configs) | exit 0; `… OK` for `pgsql-bigint`, `pgsql-ulid`, `pgsql-bigint-preflight-red`; SHA256SUMS match | `verify-pgsql.log` |
| `make-v0.2-dump.sh --verify` (all five configs) | exit 1 at `mysql-bigint`: v0.2.0 migration fails on MySQL (below) | `verify-all.log` |
| Determinism (Code Guidance command, PG configs) | `diff -r` empty, exit 0 | `determinism.log` |
| README composition / `grep -c "legacy\|parent_id"` | 9 ≥ 1; table mirrors Scope §4 | `tests/fixtures/v0.2.0/README.md` |
| `pgsql-bigint.sql` queries | `parent_id IS NULL` = 5 > 3 storages (2 N04 documents); soft-deleted grants = 2; `storage_id IS NULL` storage-scoped blobs = 1; tnull = 2; blob 19 shared by 2 storages; formats markdown/richtext/table/template; shares 1/1/1; outbox dispatched 58 / pending 6; tus uploading 4/10 | `validation-queries.txt` |
| Owner set check in `--verify` | bigint `1,2,3`; ulid `01ARZ3NDEKTSV4RRFFQ69G5FAV,…FAW,…FAX` → OK | `verify-pgsql.log` |
| `bash -n`, `pint --test scripts/fixtures` | exit 0, exit 0 | `lint.log` |

Schema anchors (D07) hold: `v_storages.owner_id` bigint in the bigint schema, char(26) ULIDs in the ulid schema;
the fixture schema is the tag's own, unmodified.

## Blocker — MySQL dumps (`mysql-bigint.sql`, `mysql-ulid.sql`)

`v0.2.0` core migrations cannot run on MySQL 8.0.46: `PanelTablesBuilder` (tag, line 1139) adds the STORED generated
column `v_nodes.sibling_active` over `parent_id`, and `parent_id` has a foreign key `ON DELETE CASCADE`. MySQL forbids
CASCADE/SET NULL actions on a base column of a stored generated column → `SQLSTATE[HY000] 1215 Cannot add foreign key
constraint`. The tag's CI has no MySQL job, so a 0.2.0 install on MySQL was never possible.

P0.3 Implementation Rules: «if the source schema diverges from the tag anchors — stop and describe; do not change the
fixture schema». OPUS 13 P0.3 («дамп для PG и MySQL») therefore needs an owner decision, e.g.:
1. amend the requirement by a D#: MySQL 0.2 data cannot exist, so no MySQL 0.2 dump; MySQL coverage of the 0.3
   upgrade starts from a fresh 0.3 install (or a later fixture);
2. or another source of «0.2 data on MySQL» chosen by the owner (any schema change is outside this item's rules).

Not claimed here: independent review `full` (B0-A, cursor/grok-4.7/high, separate session) — pending.
