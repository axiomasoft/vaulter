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
