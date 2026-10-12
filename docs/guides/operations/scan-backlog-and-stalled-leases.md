---
title: Scan backlog and stalled leases
---

# Scan backlog and stalled leases

**Symptom:** files stay unpublished; `vaulter:doctor` reports `drive.scan.obligations` as stuck or unscanned.

1. Diagnose (read-only):

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=drive.scan
```

2. Preview what the reconciler would recover, narrowed to one tenant or drive:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --dry-run --tenant=acme --drive=1
```

3. Run it for real. Work is bounded by `--limit` and `--max-seconds`, so repeat until the doctor check is green:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --tenant=acme --drive=1 --reason="stalled leases after deploy"
```

A lease that lapsed is taken over by the next run; no manual database edit is needed.
If obligations keep stalling, check that a queue worker runs the `uploads.scan.queue` queue
and that `uploads.scan.scanner_timeout_seconds` is shorter than `lease_seconds`.
