---
title: Drives purge and recount
---

# Drives purge and recount

A drive in status `deleting` is purged by a scheduled job. If `drive.drives.deleting_stuck` is
reported, preview and purge by hand:

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --dry-run --drive=7
```

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --drive=7 --sync
```

Used bytes are a counter. `drive.drives.usage_drift` means it disagrees with the retained
revisions; report the drift first, correct it second:

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:recount --dry-run --drive=7
```

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:recount --drive=7
```

Without `--drive` both commands act on all drives — always preview those runs.

## Diagnose and recover

1. Record the tenant and drive IDs, drive status, time since deletion was requested and the last purge job result. Check scheduler and queue health before starting a manual purge.
2. Preview a single drive. Confirm the retention and deletion decision, then run the synchronous purge for that same drive. An interrupted purge can be retried after the underlying database or storage error is resolved.
3. For counter drift, preview recount and compare it with retained revisions. Apply it only to the affected drive, then preview again. Confirm the doctor no longer reports drift or a stalled purge for that drive.
