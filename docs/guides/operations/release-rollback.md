---
title: Release rollback
---

# Release rollback

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A deployment causes regressions, new errors, incompatible jobs or an inability to read existing data.

## Read-only diagnosis

Capture deployed package versions, lockfile/artifact digest, last known-good release, schema/migration history and redacted error codes. Run schema diagnosis:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=core.schema
```
Compare the old code against the current schema, persisted payloads and queued jobs. Doctor reports schema checks, not compatibility of an older release. Review the exact migration up/down bodies and data transformations.

## Bounded action

Require explicit owner authorization and a verified coordinated database/blob/key backup before a rollback or data replacement. Pause traffic that writes, workers and scheduled maintenance. Rehearse the previous pinned release against a disposable copy of the current data. If compatible, redeploy that known-good immutable artifact/lockfile through the host deployment procedure, preserving required configuration and keys; restart compatible workers only after validation.

A code rollback is separate from DDL/data rollback. An additive forward migration may still have a destructive down method. Do not use `migrate:rollback` as a general production recovery step. It belongs only in an explicitly authorized disposable rehearsal after assessing data loss. If old code cannot use the schema, prefer a reviewed forward fix or owner-approved coordinated restore to the verified checkpoint.

## Postcondition

Confirm the actual installed version/digest, schema checks, authorized reads/writes and queue compatibility in the recovery environment before cutover. Track the original error code and delivery outcomes after reopening traffic. Record lost writes/replayed effects if restoring a checkpoint; a green doctor report alone does not establish release compatibility.

## Rollback

Keep both release artifacts and the pre-change checkpoint. If the rollback fails, stop writers and return to the compatible code/config/data set selected by the owner. Do not oscillate versions against incompatible schema or replay jobs blindly. See [Migration recovery](./migration-recovery) and [Backup and restore](./backup-and-restore).
