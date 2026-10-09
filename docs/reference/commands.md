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
| `vaulter:blobs:gc` | Two-phase, race-safe blob garbage collection |
| `vaulter:blobs:reconcile` | Rebuild blob `ref_count` and report dangling rows |
| `vaulter:purge-trash` | Permanently delete trashed nodes past retention |
| `vaulter:revisions:prune` | Delete non-current file revisions past the profile retention policy |
| `vaulter:sweep-uploads` | Abort and clean up stale resumable-upload sessions |
| `vaulter:relay-outbox` | Relay pending outbox events to webhook endpoints |
| `vaulter:retry-webhooks` | Re-dispatch webhook deliveries whose backoff elapsed |
| `vaulter:prune-webhooks` | Delete completed outbox rows and terminal webhook deliveries past retention |
| `vaulter:webhooks:legacy-fanout` | List or replay pre-1.0 outbox rows whose fan-out cannot be proven |
| `vaulter:events:reference` | Regenerate (or `--check`) the event catalogue table in the reference docs |

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
Registering the provider FQCN in `config('vaulter.profiles.providers')` is a deliberate next step
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


## `vaulter:blobs:gc`

Two-phase, race-safe blob garbage collection (ACL-blind, system context). Marks
unreferenced committed blobs as tombstoned with a grace deadline, then sweeps
tombstoned blobs past grace to a physical delete. The grace window comes from
`config('vaulter-media.gc.grace_hours')` (floored at 1h) unless `--grace` overrides it.

```
vaulter:blobs:gc
    {--grace=}  Override the GC grace window in hours (floored at 1h)
    {--pending} Also sweep crashed-staging PENDING blobs
```

```bash
php artisan vaulter:blobs:gc            # tombstone + sweep past grace
php artisan vaulter:blobs:gc --grace=48 # custom grace window
php artisan vaulter:blobs:gc --pending  # also clear crashed-staging rows
```

::: danger Sweeping deletes bytes
The sweep phase physically deletes blob bytes. Keep `grace_hours` ≥ your worst-case
multipart-commit latency so an in-flight upload can resurrect a tombstoned blob first.
:::

See [Recipes → Blob GC](/guide/recipes).

## `vaulter:blobs:reconcile`

Rebuild blob `ref_count` from the edge counts in `v_file_version_blobs` and report
dangling rows. The edge — not `ref_count` — is the liveness truth; this command
repairs the reconciled cache.

```
vaulter:blobs:reconcile
```

```bash
php artisan vaulter:blobs:reconcile
```

## `vaulter:purge-trash`

Permanently purge trashed nodes past their retention window. Finds the trash **roots**
whose `trashed_at` is older than `now() - retention_days` and force-deletes each
subtree, so the version↔blob edges detach (the bytes become GC-eligible) and the quota
is released. Only roots (`trash_root_id = id`) are eligible: purging a root removes its whole subtree,
including descendants trashed earlier on their own, so nothing is purged twice.

```
vaulter:purge-trash
    {--days=} Override the retention window (config vaulter-drive.trash.retention_days)
```

```bash
php artisan vaulter:purge-trash           # uses config retention (default 30 days)
php artisan vaulter:purge-trash --days=7  # custom retention
```

See [Usage → Search & trash](/guide/search-trash).

## `vaulter:drives:purge`

Start or resume the purge of drives with status `deleting` (PLAN2.D27). The purge is idempotent, so
running it again after a crash finishes the job: it aborts open uploads, purges the children of the
root in batches of 100 through `PurgeNodeAction` as the system actor `drive-purge`, then removes the
root and the drive row. Activity and outbox rows stay.

```
vaulter:drives:purge
    {--drive=*}   Only these drive ids
    {--dry-run}   Print the node count per drive and change nothing
    {--sync}      Purge in this process instead of queueing PurgeDriveJob
```

## `vaulter:drives:recount`

Recompute `used_bytes` of drives from their retained file revisions, trash included (PLAN2.D48),
under the exclusive tree lock of each drive, so a concurrent upload or revision delete is never
overwritten by a stale sum. Reports and logs every drift it finds; running it again changes nothing.
Runs as maintenance outside any tenant, scoped to the drives it names.

```
vaulter:drives:recount
    {--drive=*}   Only these drive ids
    {--dry-run}   Report the drift without correcting it
```

## `vaulter:revisions:prune`

Apply each drive profile's `RevisionRetentionPolicy` (PLAN2.D48): delete the non-current revisions
of every file — trashed files included — past `maxNoncurrentRevisions` or older than
`maxNoncurrentAgeDays`, never the head, a pinned revision or one superseded less than
`minNoncurrentAgeDays` ago. Each deletion detaches the blob edges (the bytes become GC-eligible)
and releases the quota. A drive that fails is logged and the others still run.

```
vaulter:revisions:prune
    {--drive=*}  Only these drive ids
    {--dry-run}  Report what would be deleted
```

```bash
php artisan vaulter:revisions:prune --dry-run
php artisan vaulter:revisions:prune          # schedule daily
```

See [Usage → Uploads → Revision retention](/guide/uploads#revision-retention).

## `vaulter:sweep-uploads`

Abort and clean up stale resumable-upload sessions past their TTL
(`config('vaulter-drive.uploads.resumable.session_ttl_hours')`). The staged temp file and any S3
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
pending `v_outbox` row fans out into one `v_webhook_deliveries` row per eligible
endpoint, and the HTTP POST is queued after the rows commit. The relay leases a row
for `webhooks.relay.claim_seconds` and writes `webhooks.relay.fanout_chunk` endpoints
per transaction; the row becomes `completed` together with its last deliveries, so a
relay that dies is resumed by the next run once the lease lapses. Several relays may
run at once. At-least-once with exponential backoff + DLQ. Gated by
`features.webhooks`. See [Webhooks → Delivery guarantees](/guide/webhooks#delivery-guarantees).

```
vaulter:relay-outbox
    {--limit=} Max outbox rows to drain this tick
```

```bash
php artisan vaulter:relay-outbox --limit=500
```

See [Recipes → Reliability & webhooks](/guide/recipes).

## `vaulter:retry-webhooks`

Re-dispatch webhook deliveries whose backoff window has elapsed, and pending
deliveries whose first job was lost (still unclaimed `webhooks.relay.claim_seconds`
after they became due). Gated by `features.webhooks`.

```
vaulter:retry-webhooks
```

```bash
php artisan vaulter:retry-webhooks
```

---

## `vaulter:events:reference`

Regenerate the event catalogue table between the `<!-- events:start -->` and
`<!-- events:end -->` markers of `docs/reference/events.md` and
`docs/ru/reference/events.md` from the `EventSpec` of every `EventType`. With `--check`
nothing is written and the exit code is 1 when a page differs (for CI). It is a
maintenance command for the Vaulter repository, not for host applications.

```bash
php artisan vaulter:events:reference
php artisan vaulter:events:reference --check
```

---

## `vaulter:prune-webhooks`

Delete COMPLETED outbox rows (`completed`, `fanned_out_at` older than the retention
window) and TERMINAL webhook deliveries (`delivered`/`dead`, older than the retention
window). A `pending` outbox row — even one whose fan-out stopped half way — and a
`pending`/`failed` delivery are never touched, however old. Gated by
`features.webhooks` (no-ops with a notice when off). The window comes from
`config('vaulter.webhooks.retention_days')` (default 30) unless `--days` overrides it.
Pruned rows cannot be restored or replayed.

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

## `vaulter:webhooks:legacy-fanout`

The relay before 1.0 marked an outbox row `dispatched` before writing its deliveries,
so a crash could lose them. The upgrade turns those rows into `completed` rows with
`legacy_fanout = true`. A legacy row without any delivery is ambiguous: no endpoint
was subscribed, or the fan-out was lost — the data cannot tell. This command lists
those rows (`event_id`, `event`, `created_at`, `drive_id`); with `--replay` it returns
them to the relay, which fans them out to the endpoints eligible **now** (an endpoint
that was inactive when the event happened may receive it; one that already has the
delivery does not get a second). Rows the prune already deleted are gone. Gated by
`features.webhooks`.

```
vaulter:webhooks:legacy-fanout
    {--since=}   Only rows created at or after this date/time
    {--until=}   Only rows created at or before this date/time
    {--replay}   Return the listed rows to the relay queue
    {--dry-run}  With --replay, report what would be replayed without changing anything
```

```bash
php artisan vaulter:webhooks:legacy-fanout --since=2026-09-01
php artisan vaulter:webhooks:legacy-fanout --since=2026-09-01 --replay --dry-run
php artisan vaulter:webhooks:legacy-fanout --since=2026-09-01 --replay
```

---

## Scheduling

The six maintenance commands are schedule-friendly. A representative schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes();
Schedule::command('vaulter:sweep-uploads')->hourly();
Schedule::command('vaulter:blobs:gc')->daily();
Schedule::command('vaulter:purge-trash')->daily();
Schedule::command('vaulter:blobs:reconcile')->weekly();
Schedule::command('vaulter:prune-webhooks')->weekly();
```

## See also

- [Configuration reference](/reference/configuration) — `vaulter-media.gc.*`, `vaulter-drive.trash.*`, `vaulter-drive.uploads.*`, `webhooks.*`, `features.webhooks`
- [Events reference](/reference/events) — what the relay fans out
- [Facade & Manager](/reference/facade) — the runtime API
