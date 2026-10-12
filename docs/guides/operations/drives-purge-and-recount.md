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
