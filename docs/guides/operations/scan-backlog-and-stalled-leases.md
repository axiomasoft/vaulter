---
title: Scan backlog and stalled leases
---

# Scan backlog and stalled leases

**Symptom:** files stay unpublished (`pending` / `scanning`); `php artisan vaulter:doctor` reports `drive.scan.obligations_stuck`, `drive.scan.obligations_unscanned`, or `drive.scan.backfill_stalled`.

## Diagnose (read-only)

Run the drive scan checks to separate missing workers, daemon failures, expired leases, and stalled policy backfills:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=drive.scan
```

- `drive.scan.obligations_stuck` — expired leases or obligations overdue by 30 minutes.
- `drive.scan.obligations_unscanned` — work that settled without a verdict, grouped by reason.
- `drive.scan.backfill_stalled` — existing files unclaimed for 24 hours during rollout.
- `drive.scan.scanner_missing` / `drive.scan.clamav_version_unsupported` — scanner configuration or daemon version issue.
- `drive.scan.scope_invalid` — obligation or backfill rows with missing or cross-tenant drives.

Preview what the bounded reconciler will claim for one tenant or drive:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --dry-run --tenant=acme --drive=1 --json
```

## Recover

Run reconciliation bounded by `--limit` (`1..500`, default `100`) and `--max-seconds` (`1..60`, default `30`). Exit codes: `0` ok, `2` invalid bounds or queue config, `1` unexpected error:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --tenant=acme --drive=1 --limit=100 --max-seconds=30 --reason="stalled leases after deploy"
```

Repeat bounded runs (or pass `--all-tenants` during maintenance) until `vaulter:doctor --check=drive.scan` returns `drive.scan.obligations_ok`:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --all-tenants --limit=100 --max-seconds=30 --reason="maintenance reconcile"
```

For failed revisions after a temporary scanner outage (never for quarantined files), preview and retry only failed work:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --retry-failed --dry-run --tenant=acme --drive=1
```

## Prevent repeat stalls

- An expired lease is reclaimed automatically on the next run; never edit scan rows by hand.
- Ensure a queue worker runs `vaulter-drive.uploads.scan.queue` and `vaulter-drive.schedule.scan_reconcile` (`everyFiveMinutes`) runs while `vaulter.schedule.enabled` is `true`.
- Keep `vaulter-drive.uploads.scan.scanner_timeout_seconds` (`60`) below `lease_seconds` (`120`), and verify `max_attempts` (`5`) and `publish_cooldown_seconds` (`30`).
