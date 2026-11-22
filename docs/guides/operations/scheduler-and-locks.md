---
title: Scheduler and locks
---

# Scheduler and locks

Vaulter puts its maintenance commands on your Laravel scheduler. Two switches matter:

- `vaulter.schedule.enabled` — set it to `false` when your host schedules the commands itself;
- each task key (`outbox_relay`, `uploads_sweep`, …) — `null` switches that task off.

Your server must run `php artisan schedule:run` every minute. With several app servers,
`onOneServer` only works when the cache store is shared and supports atomic locks (redis, database,
memcached — not `file` or `array`). Check:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.scheduler
```

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.cache
```

A task that runs twice usually means a non-shared lock store; a task that never runs means
the scheduler is not triggered or `schedule.enabled` is `false`.

## Diagnose and recover

1. Run `php artisan schedule:list` on each scheduler host. Compare the deployed task settings, environment, timezone and cache store. Check when the host last invoked Laravel's scheduler.
2. If two hosts run the same job, verify both reach the same lock store with the same cache prefix. Bring up one scheduler first, then restore the others once a single invocation per interval is visible.
3. For a stalled task, verify the previous process is gone before clearing a stuck scheduler mutex. Run the affected command once within its documented scope, then confirm scheduled execution on the next interval.

When rolling out the 64-bit PostgreSQL advisory locks, drain old HTTP writers, upload workers and CAS
maintenance jobs before starting the updated processes. Confirm that uploads and CAS reconciliation
complete after the cutover. Use the same drain when rolling back to a release with 32-bit locks.
