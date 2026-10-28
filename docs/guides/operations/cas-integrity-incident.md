---
title: CAS integrity incident
---

# CAS integrity incident

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

Downloads return `content_unavailable` (423), a blob has `integrity_failed_at`, or scrub reports missing, size or digest failures.

## Read-only diagnosis

Determine the actual dedup scope from configuration and affected references; tenant scope is only appropriate when that is the stored scope. Replace `TENANT_ID` below with the validated tenant id, or use the actual drive/off scope. Inspect `media.blobs.integrity`:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=media.blobs.integrity
```

<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:scrub --dry-run --scope='tenant:TENANT_ID' --limit=100 --max-bytes=104857600 --max-seconds=30
```
Read existing `vaulter_blobs_scrub_failed_total` by reason and scrub checked/byte counters where metrics are configured. Record failed ids, unreadable objects and stop reason. Exit 0 covers the visited sample only; exit 1 includes unreadable objects, and exit 2 means invalid options.

## Bounded action

Obtain explicit owner authorization and a verified backup/object version before replacing bytes or changing corruption state. Isolate affected writes/download traffic and retain the damaged version as incident evidence. `--dry-run` reads and reports without marking or healing; ordinary scrub records integrity status but also does not restore bytes. There is no general heal CLI command. Restore verified original bytes from backup and use the supported same-sha256 ingest into the same scope to heal the integrity mark under the blob lock; do not manually clear failure timestamps to bypass refusal. Coordinate exact object handling with the storage owner. See [Blob integrity](/guides/blob-integrity).

## Postcondition

Verify restored size and SHA-256 against the trusted manifest, repeat the bounded scrub and confirm the affected ids were actually visited. Check that the integrity mark is cleared by the supported recovery path and an authorized download returns the expected bytes. Confirm unrelated scopes and pins are unchanged. A clean sample alone is not full-store qualification.

## Rollback

Keep traffic isolated if checks fail. Preserve the original damaged version and logs; return to the last verified coordinated database/object checkpoint with owner authorization if repair cannot be completed. Never serve known-corrupt bytes or delete pins to silence the alarm.
