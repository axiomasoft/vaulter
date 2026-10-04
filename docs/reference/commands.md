---
title: Artisan commands
description: Every Vaulter Artisan command — signatures, options, and what they do.
outline: deep
---

# Artisan commands

Vaulter registers ten Artisan commands: an install command (from
spatie/laravel-package-tools) plus nine package commands wired in
`VaulterServiceProvider`. The maintenance commands (GC, trash purge, upload sweep,
outbox relay, webhook retry, webhook prune) are designed to be scheduled.

| Command | Purpose |
|---|---|
| `vaulter:install` | Publish config + migrations, run them, offer to star the repo |
| `vaulter:make:profile` | Scaffold a storage-panel provider class |
| `vaulter:gc-blobs` | Two-phase, race-safe blob garbage collection |
| `vaulter:reconcile-blobs` | Rebuild blob `ref_count` and report dangling rows |
| `vaulter:purge-trash` | Permanently delete trashed nodes past retention |
| `vaulter:sweep-uploads` | Abort and clean up stale resumable-upload sessions |
| `vaulter:relay-outbox` | Relay pending outbox events to webhook endpoints |
| `vaulter:retry-webhooks` | Re-dispatch webhook deliveries whose backoff elapsed |
| `vaulter:prune-webhooks` | Delete settled outbox rows and terminal webhook deliveries past retention |

---

## `vaulter:install`

Provided by spatie/laravel-package-tools. Publishes the config file and migrations,
asks to run the migrations, and offers to star the repo on GitHub.

```bash
php artisan vaulter:install
```

Equivalent manual steps:

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-migrations
php artisan migrate
```

See [Guide → Installation](/guide/installation).

## `vaulter:make:profile`

Scaffold a consumer-owned `ProfileProvider` class in `app/Vaulter/Profiles` from a
stub. It does **not** touch the published config and does **not** generate migrations —
a panel is a zero-migration config-envelope over the shared `v_*` schema by default.
Registering the provider FQCN in `config('vaulter.panels')` is a deliberate next step
the command prints.

```
vaulter:make:profile
    {name}        The panel id (snake/kebab), e.g. project_drive
    {--host=}     The host model FQCN this storage embeds into
    {--no-grants} Generate a panel without per-node grants
    {--force}     Overwrite the provider if it already exists
```

```bash
php artisan vaulter:make:profile project_drive --host="App\Models\Project"
```

See [Concepts → Storage panels](/guide/storage-panels).


## `vaulter:gc-blobs`

Two-phase, race-safe blob garbage collection (ACL-blind, system context). Marks
unreferenced committed blobs as tombstoned with a grace deadline, then sweeps
tombstoned blobs past grace to a physical delete. The grace window comes from
`config('vaulter.gc.grace_hours')` (floored at 1h) unless `--grace` overrides it.

```
vaulter:gc-blobs
    {--grace=}  Override the GC grace window in hours (floored at 1h)
    {--pending} Also sweep crashed-staging PENDING blobs
```

```bash
php artisan vaulter:gc-blobs            # tombstone + sweep past grace
php artisan vaulter:gc-blobs --grace=48 # custom grace window
php artisan vaulter:gc-blobs --pending  # also clear crashed-staging rows
```

::: danger Sweeping deletes bytes
The sweep phase physically deletes blob bytes. Keep `grace_hours` ≥ your worst-case
multipart-commit latency so an in-flight upload can resurrect a tombstoned blob first.
:::

See [Recipes → Blob GC](/guide/recipes).

## `vaulter:reconcile-blobs`

Rebuild blob `ref_count` from the edge counts in `v_file_version_blobs` and report
dangling rows. The edge — not `ref_count` — is the liveness truth; this command
repairs the reconciled cache.

```
vaulter:reconcile-blobs
```

```bash
php artisan vaulter:reconcile-blobs
```

## `vaulter:purge-trash`

Permanently purge trashed nodes past their retention window. Finds the trash **roots**
whose `trashed_at` is older than `now() - retention_days` and force-deletes each
subtree, so the version↔blob edges detach (the bytes become GC-eligible) and the quota
is released. A trashed node whose parent is also trashed is skipped (no double-purge).

```
vaulter:purge-trash
    {--days=} Override the retention window (config vaulter.trash.retention_days)
```

```bash
php artisan vaulter:purge-trash           # uses config retention (default 30 days)
php artisan vaulter:purge-trash --days=7  # custom retention
```

See [Usage → Search & trash](/guide/search-trash).

## `vaulter:sweep-uploads`

Abort and clean up stale resumable-upload sessions past their TTL
(`config('vaulter.uploads.session_ttl_hours')`). The staged temp file and any S3
multipart state are reclaimed, and the session status flips to `expired`.

```
vaulter:sweep-uploads
```

```bash
php artisan vaulter:sweep-uploads
```

::: tip Pair with an S3 lifecycle rule
The sweeper aborts in-flight multiparts it knows about. Add an
`AbortIncompleteMultipartUpload` lifecycle rule as the backstop for any it does not.
:::

See [Usage → Uploads](/guide/uploads).

## `vaulter:relay-outbox`

Relay pending outbox events to webhook endpoints and re-dispatch due retries. Each
pending `v_outbox` row fans out into one signed `v_webhook_deliveries` row per
subscribed active endpoint, and the HTTP POST is queued. At-least-once with
exponential backoff + DLQ. Gated by `features.reliability`.

```
vaulter:relay-outbox
    {--limit=} Max outbox rows to drain this tick
```

```bash
php artisan vaulter:relay-outbox --limit=500
```

See [Recipes → Reliability & webhooks](/guide/recipes).

## `vaulter:retry-webhooks`

Re-dispatch webhook deliveries whose backoff window has elapsed. Gated by
`features.reliability`.

```
vaulter:retry-webhooks
```

```bash
php artisan vaulter:retry-webhooks
```

---

## `vaulter:prune-webhooks`

Delete SETTLED outbox rows (`dispatched`, older than the retention window) and
TERMINAL webhook deliveries (`delivered`/`dead`, older than the retention window).
An in-flight row (`pending`/`failed`) is never touched — the relay/retry sweep still
owns it. Gated by `features.reliability` (no-ops with a notice when off). The window
comes from `config('vaulter.webhooks.retention_days')` (default 30) unless `--days`
overrides it.

```
vaulter:prune-webhooks
    {--days=}    Override the retention window in days
    {--dry-run}  Report what would be pruned without deleting
```

```bash
php artisan vaulter:prune-webhooks
php artisan vaulter:prune-webhooks --dry-run
php artisan vaulter:prune-webhooks --days=7
```

See [Recipes → Reliability & webhooks](/guide/recipes).

---

## Scheduling

The six maintenance commands are schedule-friendly. A representative schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes();
Schedule::command('vaulter:sweep-uploads')->hourly();
Schedule::command('vaulter:gc-blobs')->daily();
Schedule::command('vaulter:purge-trash')->daily();
Schedule::command('vaulter:reconcile-blobs')->weekly();
Schedule::command('vaulter:prune-webhooks')->weekly();
```

## See also

- [Configuration reference](/reference/configuration) — `gc.*`, `trash.*`, `uploads.*`, `webhooks.*`, `features.reliability`
- [Events reference](/reference/events) — what the relay fans out
- [Facade & Manager](/reference/facade) — the runtime API
