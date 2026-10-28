---
title: Migration recovery
---

# Migration recovery

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

Deployment stops on a failed migration, a table/key mismatch appears, or some DDL was applied while migration history did not advance.

## Read-only diagnosis

Record the driver (PostgreSQL/MySQL), exact failed migration, exception code and migration-history entry. Compare actual tables, columns, indexes and constraints with that migration using read-only database introspection; capture no row contents. Run:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=core.schema
```
`core.schema.tables` checks missing/pending schema; `core.schema.key_types` checks key types. Neither proves all custom indexes or partial data transformations are correct. Inspect the exact migration and its transaction behavior; do not assume PostgreSQL rolled everything back or MySQL made the sequence atomic.

## Bounded action

Obtain explicit owner authorization and a verified database/blob/key backup before any retry or repair. Stop writers, workers and schedulers. On a disposable restored copy, determine which statements committed and whether retry is safe; PostgreSQL transactional DDL and MySQL implicit DDL commits require different assessment. Rehearse a narrow forward repair or the original migration only after its preconditions are restored. Apply the reviewed recovery through the owner deployment procedure. Do not manually mark a migration complete without verifying its entire schema/data postcondition.

Prefer coordinated restore if a safe forward repair is unavailable. Code rollback does not reverse DDL/data. `migrate:rollback` is only a disposable, explicitly authorized rehearsal after reviewing destructive down behavior, never a blanket production recommendation.

## Postcondition

Compare actual schema and migration history against the intended release, including constraints/indexes and transformed data counts. Repeat schema diagnosis, then verify representative tenant reads/writes and queue behavior before reopening writers. Keep the failed and repaired schema evidence; clean doctor findings are necessary evidence, not full migration qualification.

## Rollback

If repair fails, keep traffic stopped and restore the last verified coordinated checkpoint with matching release/config/keys under owner authorization. Preserve partial-state evidence and account for writes since the backup. Avoid repeated blind retries or destructive down migrations. See [Release rollback](./release-rollback).
