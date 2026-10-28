---
title: Backup and restore
---

# Backup and restore

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A database or object-store loss, an inconsistent restore, or a recovery rehearsal requires a coordinated recovery point. Missing bytes may produce `content_unavailable` (HTTP 423).

## Read-only diagnosis

Record the affected tenant and drive ids, release/lockfile, schema version and backup timestamp. Inspect the backup manifest and object versions without changing the live store. Start with:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=core.schema
```
Doctor schema checks report tables and key types, not recoverability. Use the bounded sample below on an isolated restored copy; replace `TENANT_ID` with a validated actual tenant id. A sample cannot certify the whole store.
<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:scrub --dry-run --scope='tenant:TENANT_ID' --limit=100 --max-bytes=104857600 --max-seconds=30
```


## Bounded action

Obtain explicit owner authorization and a verified backup before replacing any database, bytes or keys. Stop writers, workers and scheduled maintenance using the host deployment procedure. Back up the database (including references, outbox and receipts), all referenced blob disks/object versions, and the matching application encryption/signing secrets through the private secret manager. Keep the release, config and manifest alongside the recovery point; never put raw secrets in incident reports.

Restore into an isolated environment: matching release/config and keys first, database and object versions from the same recovery point next. Keep outbound delivery disabled while reconciling. Check tenant ownership of references as well as bytes; shared storage scope is not a tenant export. No built-in Vaulter backup/restore command exists.

## Postcondition

Repeat schema diagnosis and the bounded scrub; record checked count, bytes, failures, unreadable objects and stop reason. Compare manifest hashes and reference counts, then exercise authorized reads for the affected tenant and denied reads for another tenant. Explicitly assess change-journal cursor invalidation after a database restore before reconnecting clients. Only the owner may approve cutover after these checks; no runtime recovery is qualified by this document.

## Rollback

Keep the pre-cutover database/object/key set intact. If validation fails, keep writers stopped and return to that coordinated set under owner authorization. Restoring an older database alone can lose newer writes and break references; do not mix recovery points. See [CAS integrity](./cas-integrity-incident) for damaged objects.
