---
title: Artisan commands
description: Every Vaulter Artisan command — signatures, options, and what they do.
outline: deep
---

# Artisan commands

Vaulter's commands are named `vaulter:<area>:<verb>`; the exceptions are the entry points
`vaulter:install` and `vaulter:doctor`. The maintenance commands (GC, trash purge, upload sweep, outbox relay,
webhook retry, webhook prune, scan reconcile) are put on the host scheduler by the packages
themselves — see [Scheduling](#scheduling).

| Command | Purpose |
|---|---|
| `vaulter:install` | Publish the config of every installed package, print the host settings to apply, optionally migrate |
| `vaulter:doctor` | Read-only diagnosis of the host's Vaulter setup; `--production` raises part of the warnings to errors |
| `vaulter:uploads:sweep` | Abort and clean up stale resumable-upload sessions |
| `vaulter:scan:reconcile` | Recover scan intents, backfill discovery and committed-upload cleanup (bounded) |
| `vaulter:scan:rescan` | Queue a rescan of file revisions for a tenant, drive or revision set |
| `vaulter:drives:purge` | Purge soft-deleted drives |
| `vaulter:drives:recount` | Report (or correct) drive size and node-count drift |
| `vaulter:make:profile` | Scaffold a storage-panel provider class |
| `vaulter:blobs:gc` | Two-phase, race-safe blob garbage collection |
| `vaulter:blobs:reconcile` | Rebuild blob `ref_count` and report dangling rows |
| `vaulter:trash:purge` | Permanently delete trashed nodes past retention |
| `vaulter:revisions:prune` | Delete non-current file revisions past the profile retention policy |
| `vaulter:relay` | Relay the outbox to its consumers: register, reconcile, deliver, hold |
| `vaulter:relay:replay` | Replay a range of the outbox to one consumer, or acknowledge its gap |
| `vaulter:outbox:prune` | Delete outbox events and deliveries past retention without losing undelivered ones |
| `vaulter:webhooks:retry` | Re-dispatch webhook deliveries whose retry delay elapsed |
| `vaulter:webhooks:prune` | Delete terminal webhook deliveries past retention |
| `vaulter:webhooks:redeliver` | Send finished webhook deliveries again with the same `webhook-id` |
| `vaulter:events:reference` | Regenerate (or `--check`) the event catalogue table in the reference docs |
| `vaulter:errors:reference` | Regenerate (or `--check`) the problem-code reference in the docs |
| `vaulter:changes:prune` | Delete drive change-journal entries past `changes.retention_days` |
| `vaulter:changes:reset` | Expire every change cursor of a drive, forcing its clients to resynchronize |

---

## `vaulter:install`

Publishes the config file of every installed Vaulter package (`vaulter-config`,
`vaulter-media-config`, `vaulter-drive-config`, `vaulter-documents-config`,
`vaulter-filament-config`, `vaulter-azgard-config`), prints the host settings it will **not**
edit, and runs the migrations when asked. Safe to run again: an already published file is
skipped (`--force` overwrites it) and nothing in `config/*.php` or `.env` is ever rewritten.

```bash
php artisan vaulter:install
```

| Option | Meaning |
|---|---|
| `--middleware=` | Auth middleware for the authenticated route stack (default `auth:sanctum`) |
| `--driver=` | `database` (default), `gate`, `spatie` or a key of `access.permission_drivers` |
| `--migrate` | Run `php artisan migrate` without asking |
| `--force` | Overwrite config files that are already published |

Interactively the command asks for the middleware and the driver, whether to migrate (default
no) and whether to star the repo; without a terminal it uses the options or the defaults and
does not migrate unless `--migrate` is given. It prints the lines to apply
(`VAULTER_PERMISSION_DRIVER=…` and the `routes.middleware.authenticated` entry) and ends with the
[`vaulter:doctor`](#vaulter-doctor) report. The install is not a gate: it exits `0` whatever the
doctor finds.

## `vaulter:modules`

```bash
php artisan vaulter:modules [--json]
```

Lists every installed (offered) module: package, protocol, activation policy (`auto` /
`opt-in`), whether it is active and why (`default` or `override` from `vaulter.modules`), the
schema migrations the database has not run, and the capabilities and slots it defines or
contributes to. Exit `0` for a valid module graph, `1` for an invalid one — the reasons are
printed and no module is active. It is registered whatever the graph. See
[Modules](/concepts/modules).

## `vaulter:module:uninstall`

```bash
php artisan vaulter:module:uninstall <module> [--force] [--purge]
```

A dry run by default: plans the removal of the module's schema — its migrations and tables,
the installed modules that require it, the foreign keys other owners hold on its tables — and
prints why it would be refused. Exit `1` when anything refuses. Until the uninstall of the owner
migrations lands (P4), `--force` (roll the owner's migrations back) and `--purge` (delete its
rows and its own media pins) refuse with "the uninstall of the owner migrations is not available yet (P4)". Disabling
a module never uninstalls it.

## `vaulter:doctor`

Read-only diagnosis of the host's Vaulter setup. Every installed package contributes checks; a
check reports `ok`, `warning` or `error` findings, each with a stable `code`, a message and — when
a command fixes it — a hint. Doctor never repairs anything.

```bash
php artisan vaulter:doctor                          # all checks, human-readable
php artisan vaulter:doctor --production             # the production profile
php artisan vaulter:doctor --json                   # one vaulter.doctor/v1 document on stdout
php artisan vaulter:doctor --check=drive.scan       # only the checks under a key prefix
php artisan vaulter:doctor --probe-storage          # also write a test object to each storage disk
```

| Option | Meaning |
|---|---|
| `--production` | Check against the production profile: the checks marked *warning → error* below become errors, and the storage disks are probed |
| `--json` | Print one `vaulter.doctor/v1` document and nothing else on stdout (usage errors go to stderr) |
| `--check=` | Run only the checks whose key equals the prefix or starts with it followed by a dot; repeatable. A prefix that matches nothing exits `2` |
| `--sample=1000` | Row limit of the sampling checks (integrity, scan scope, drive usage) |
| `--probe-storage` | Allow `media.disk.probe` to write, read and delete `.vaulter-doctor/<ulid>` on each disk. Without it (and without `--production`) the check reports `not evaluated; pass --probe-storage` |

Exit code: `1` when any finding is an `error`, `2` for a bad option, otherwise `0`. Warnings do not
fail the run. A check that throws becomes the error `doctor.check_failed` naming the exception
class; add `-v` to print the message in the human output (it can contain a connection string; the JSON never carries it). Evidence holds counts, names and flags only —
never a secret.

The JSON document is described by `packages/core/resources/schemas/doctor-v1.schema.json`:

```json
{"schema":"vaulter.doctor/v1","production":false,"generated_at":"2026-10-11T12:00:00Z",
 "summary":{"ok":14,"warning":2,"error":0},
 "checks":[{"key":"core.queue","title":"Queue connection","findings":[
   {"severity":"warning","code":"core.queue.synchronous","message":"…","hint":"…","evidence":{"connection":"sync","driver":"sync"}}]}],
 "exit_code":0}
```

| Key | Looks at | Non-production → production |
|---|---|---|
| `core.database.connection` | The Vaulter connection answers; the driver is PostgreSQL, MySQL or MariaDB | SQLite: warning → error |
| `core.schema.tables` | Every Vaulter table exists; no Vaulter migration is pending | error → error |
| `core.schema.key_types` | Key columns match `ids.strategy` / `ids.host_keys` (PostgreSQL, MySQL, MariaDB) | error → error |
| `core.schema.integrity` | One root per drive; node kind ⇄ file/document row; head revision belongs to its aggregate (sampled) | warning → warning |
| `core.config.valid` | Every installed package's boot validation, listed together | error → error |
| `core.config.deprecated_keys` | Deprecated config keys that are still set | warning → warning |
| `core.routes.auth` | `routes.middleware.authenticated` has an auth middleware whose guard exists | error → error |
| `core.access.driver` | The permission driver is real and resolves; a test-only driver is refused in production | error → error |
| `core.tenancy` | With tenancy on, the resolver class resolves (its value needs a request) | error → error |
| `core.queue` | The default queue is not `sync`/`null` | warning → warning |
| `core.scheduler` | `vaulter.schedule.enabled` or `vaulter:*` tasks on the scheduler | warning → warning |
| `core.cache` | The default cache is shared (not `array`/`file`) and has atomic locks | warning → warning |
| `core.outbox.lag` | The oldest due outbox delivery of each consumer waited less than 10 minutes | warning → warning |
| `core.outbox.leases` | No outbox delivery holds a relay lease expired for more than 5 minutes | warning → error |
| `core.outbox.consumer_gaps` | No detached consumer has an unacknowledged gap (error); paused consumers are reported (warning) | error → error |
| `core.outbox.settle_window` | `outbox.settle_window_seconds` outlasts the database's transaction limits | warning → warning |
| `core.webhooks.deliveries` | Endpoints auto-disabled for failing and deliveries that used up their retries, counted (`vaulter:webhooks:redeliver`) | warning → warning |
| `media.disk.probe` | Write, read and delete a test object on every disk (only with `--probe-storage` or `--production`) | error → error |
| `media.dedup.scope` | Tenant-wide deduplication across profiles on different disks | warning → warning |
| `drive.uploads.tmp` | The temp directory is writable and larger than `max_file_size` | error → error |
| `drive.intl` | `ext-intl` is loaded (NFC names) | warning → warning |
| `drive.scan.scanner` | An enabled scan policy has a scanner that resolves and answers to the configured key and version | error → error |
| `drive.scan.policy` | Every drive runs the configured scan policy (`vaulter:scan:rescan --activate-policy`); no backfill stalled for 24 h | warning → error |
| `drive.scan.obligations` | Expired leases and work overdue by 30 minutes (`vaulter:scan:reconcile`); work that settled unscanned, by reason | warning → error for stuck work, warning → warning for unscanned |
| `drive.scan.scope` | Scan rows whose drive is missing or of another tenant; unavailable content scope under a scan-everything policy | error → error |
| `drive.drives.deleting` | Drives in `deleting` for more than 24 h (`vaulter:drives:purge`) | warning → warning |
| `drive.drives.usage` | Stored `used_bytes` against the quota formula, same calculator as `vaulter:drives:recount`, without a lock | warning → warning |
| `documents.types` | The default type is registered; documents of unregistered types, counted | warning → warning |
| `documents.collaboration` | With collaboration on: both secrets set and different, hub URL (`wss://` in production), token lifetime ≤ 3600 s, `firebase/php-jwt` installed | error → error |
| `azgard.dependency` | AzGuard is installed and the bridge guard resolves | error → error |

The `drive.scan.*` checks run whether or not `vaulter.modules.webhooks` is on: scan work is mandatory
content-safety work, not a webhook. `media.disk.probe` is the only check that writes; it removes
its object in every case.

## Scheduling

With `vaulter.schedule.enabled` (env `VAULTER_SCHEDULE_ENABLED`, default `true`) the packages
register their maintenance commands on Laravel's scheduler, each with `withoutOverlapping()`
and `onOneServer()`, so a host needs no `Schedule::command(...)` lines of its own (remove
existing ones to avoid double runs). A host still has to run `schedule:run` every minute, and
`onOneServer()` needs a cache store shared by all instances.

| Config key | Command | Default |
|---|---|---|
| `vaulter.schedule.outbox_relay` | `vaulter:relay` | `everyMinute` |
| `vaulter.schedule.outbox_prune` | `vaulter:outbox:prune` | `hourly` |
| `vaulter.schedule.webhooks_retry` | `vaulter:webhooks:retry` (needs `vaulter.modules.webhooks`) | `everyMinute` |
| `vaulter.schedule.webhooks_prune` | `vaulter:webhooks:prune` (needs `vaulter.modules.webhooks`) | `dailyAt:03:10` |
| `vaulter.schedule.changes_prune` | `vaulter:changes:prune` (needs `features.changes`) | `dailyAt:03:20` |
| `vaulter-drive.schedule.uploads_sweep` | `vaulter:uploads:sweep` | `hourlyAt:15` |
| `vaulter-drive.schedule.trash_purge` | `vaulter:trash:purge` | `dailyAt:03:30` |
| `vaulter-drive.schedule.drives_purge` | `vaulter:drives:purge` | `hourlyAt:25` |
| `vaulter-drive.schedule.revisions_prune` | `vaulter:revisions:prune` | `dailyAt:03:40` |
| `vaulter-drive.schedule.scan_reconcile` | `vaulter:scan:reconcile --all-tenants --reason=scheduled --limit=100 --max-seconds=30` (needs scan mode ≠ `off`, independent of webhooks) | `everyFiveMinutes` |
| `vaulter-drive.schedule.drives_recount` | `vaulter:drives:recount --dry-run` | `null` (off) |
| `vaulter-media.schedule.blobs_gc` | `vaulter:blobs:gc` | `dailyAt:04:00` |
| `vaulter-media.schedule.blobs_reconcile` | `vaulter:blobs:reconcile` | `weeklyOn:0,04:30` |

A value is `null` (task off), a frequency name without arguments (`everyMinute`,
`everyFiveMinutes`, `hourly`, `daily`, `weekly`, …), `dailyAt:HH:MM`, `hourlyAt:M`,
`weeklyOn:D,HH:MM` (0 = Sunday) or a five-field cron expression. Anything else fails boot with
`InvalidConfigurationException`.

See [Guide → Installation](/getting-started/installation).

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

See [Concepts → Storage panels](/concepts/drives-and-profiles).


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

See [Recipes → Blob GC](/guides/recipes).

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

## `vaulter:trash:purge`

Permanently purge trashed nodes past their retention window. Finds the trash **roots**
whose `trashed_at` is older than `now() - retention_days` and force-deletes each
subtree, so the version↔blob edges detach (the bytes become GC-eligible) and the quota
is released. Only roots (`trash_root_id = id`) are eligible: purging a root removes its whole subtree,
including descendants trashed earlier on their own, so nothing is purged twice.

```
vaulter:trash:purge
    {--days=} Override the retention window (config vaulter-drive.trash.retention_days)
```

```bash
php artisan vaulter:trash:purge           # uses config retention (default 30 days)
php artisan vaulter:trash:purge --days=7  # custom retention
```

See [Usage → Search & trash](/guides/trash).

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

See [Usage → Uploads → Revision retention](/guides/uploads#revision-retention).

## `vaulter:uploads:sweep`

Abort and clean up stale resumable-upload sessions past their TTL
(`config('vaulter-drive.uploads.resumable.session_ttl_hours')`). The staged temp file and any S3
multipart state are reclaimed, and the session status flips to `expired`.

```
vaulter:uploads:sweep
```

```bash
php artisan vaulter:uploads:sweep
```

::: tip Pair with an S3 lifecycle rule
The sweeper aborts in-flight multiparts it knows about. Add an
`AbortIncompleteMultipartUpload` lifecycle rule as the backstop for any it does not.
:::

See [Usage → Uploads](/guides/uploads).

## `vaulter:relay`

The outbox relay (D29). One pass registers the outbox consumers of the module graph
(pausing the ones it no longer holds), reconciles the delivery rows a process with an
older graph missed — only for events older than `outbox.settle_window_seconds` — delivers
the due rows under a fenced lease with full-jitter retries and a dead-letter state, and
detaches the consumers paused longer than `outbox.max_consumer_hold_seconds` with an
explicit gap. Without `--once` it repeats passes while work remains, for at most
`--max-seconds`. Several relays may run at once. See [The outbox](/concepts/events-outbox).

```
vaulter:relay
    {--consumer=}       Only this outbox consumer (registration and holds still cover all)
    {--limit=}          Deliveries leased per consumer and pass (default outbox.batch)
    {--once}            Run a single pass
    {--max-seconds=50}  Stop starting passes after this many seconds
```

```bash
php artisan vaulter:relay
php artisan vaulter:relay --consumer=webhooks.fanout --once
```

## `vaulter:relay:replay`

Close a gap of an outbox consumer: the events of `[--from, --to]` still in the outbox become
pending again for the consumer (done and dead rows too, with a fresh attempt budget), and
the gaps the range covers are acknowledged. Exits 1 when part of the range is no longer in
the outbox and prints the range it covered. `--ack-only` acknowledges the loss without a
replay.

```
vaulter:relay:replay
    {--consumer=}  The outbox consumer id (webhooks.fanout, …)
    {--from=}      The first outbox seq
    {--to=}        The last outbox seq
    {--ack-only}   Acknowledge the gaps of the range without replaying it
```

## `vaulter:outbox:prune`

Outbox retention: deletes events older than `outbox.retention_seconds` that have no open
delivery and that every durable consumer not detached has reconciled past, the done
deliveries of deleted events, and dead deliveries older than
`outbox.dead_retention_seconds`. Scheduled hourly.

```
vaulter:outbox:prune
```

## `vaulter:webhooks:retry`

Re-dispatch webhook deliveries whose backoff window has elapsed, and pending
deliveries whose first job was lost (still unclaimed `webhooks.retry_claim_seconds`
after they became due). Gated by `vaulter.modules.webhooks`.

```
vaulter:webhooks:retry
```

```bash
php artisan vaulter:webhooks:retry
```

---

## `vaulter:events:reference`

Regenerate the event catalogue table between the `<!-- events:start -->` and
`<!-- events:end -->` markers of `docs/reference/events.md` and
`docs/ru/reference/events.md` from the `EventSpec` of every type in the event catalogue. With `--check`
nothing is written and the exit code is 1 when a page differs (for CI). It is a
maintenance command for the Vaulter repository, not for host applications.

```bash
php artisan vaulter:events:reference
php artisan vaulter:events:reference --check
```

---

## `vaulter:errors:reference`

Regenerate the problem-code reference between the `<!-- vaulter:errors:start -->` and
`<!-- vaulter:errors:end -->` markers of `docs/reference/errors.md` and
`docs/ru/reference/errors.md` from the problem-code catalogue. With `--check` nothing is
written and the exit code is 1 when a page differs (for CI). It is a maintenance command for the
Vaulter repository, not for host applications.

```bash
php artisan vaulter:errors:reference
php artisan vaulter:errors:reference --check
```

---

## `vaulter:webhooks:prune`

Delete TERMINAL webhook deliveries (`delivered`/`dead`, older than the retention
window). A `pending`/`failed` delivery is never touched, however old. The outbox has its
own retention, `vaulter:outbox:prune`. Gated by
`vaulter.modules.webhooks` (the command exists only while the webhooks module is active). The window comes from
`config('vaulter.webhooks.retention_days')` (default 30) unless `--days` overrides it.
Pruned rows cannot be restored or replayed.

```
vaulter:webhooks:prune
    {--days=}    Override the retention window in days
    {--dry-run}  Report what would be pruned without deleting
```

```bash
php artisan vaulter:webhooks:prune
php artisan vaulter:webhooks:prune --dry-run
php artisan vaulter:webhooks:prune --days=7
```

See [Recipes → Reliability & webhooks](/guides/recipes).


## `vaulter:webhooks:redeliver`

Send finished deliveries again: one by id, or every delivery of a status (default `dead`)
filtered by endpoint and age. Each goes through `RedeliverWebhookAction`: the same row returns to
`pending` with `attempts = 0`, so the receiver gets the **same `webhook-id`**; a delivery whose
endpoint is inactive, or that is still `pending`/`failed`, is reported and skipped. Only rows still
inside `webhooks.retention_days` exist to redeliver. Exit code is non-zero when nothing could be
sent. Gated by `vaulter.modules.webhooks`. See [Webhooks → Redelivery](/guides/webhooks#redelivery).

```
vaulter:webhooks:redeliver
    {delivery?}    Id of one delivery
    {--endpoint=}  Only deliveries of this endpoint id
    {--since=}     Only deliveries created at or after this date/time
    {--status=dead} delivered or dead
```

```bash
php artisan vaulter:webhooks:redeliver 1042
php artisan vaulter:webhooks:redeliver --endpoint=7 --since=2026-10-01
```

---

## `vaulter:changes:prune`

Delete [change-journal](/concepts/events) entries older than the retention window, drive by drive,
in batches of 1000. Each batch holds the drive's journal head `FOR UPDATE` and raises
`pruned_through` in the same transaction as its `DELETE`, so a client whose cursor is behind the
pruned entries gets `410 cursor_expired` instead of a silent gap; mutations of the drive wait for
at most one batch. The window comes from `config('vaulter.changes.retention_days')` (default 30)
unless `--days` overrides it. Schedule it daily.

```
vaulter:changes:prune
    {--days=}     Override the retention window in days (a positive integer)
    {--drive=*}   Only these drive ids
```

```bash
php artisan vaulter:changes:prune            # schedule daily
php artisan vaulter:changes:prune --days=7 --drive=42
```

---

## `vaulter:changes:reset`

Start a new epoch of one drive's change journal: every cursor issued for the drive expires
(`410 cursor_expired`) and its clients resynchronize with a full listing. Use it after restoring
the database from a backup, or when the journal no longer matches the drive (for example after
`features.changes` was switched off for a while).

```bash
php artisan vaulter:changes:reset 42
```

---

## Scheduling

The maintenance commands are schedule-friendly. A representative schedule:

These lines are only needed when `vaulter.schedule.enabled` is `false`; otherwise the packages already schedule these commands and the snippet would run each of them twice.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay')->everyMinute()->withoutOverlapping();
Schedule::command('vaulter:outbox:prune')->hourly();
Schedule::command('vaulter:webhooks:retry')->everyFiveMinutes();
Schedule::command('vaulter:uploads:sweep')->hourly();
Schedule::command('vaulter:blobs:gc')->daily();
Schedule::command('vaulter:trash:purge')->daily();
Schedule::command('vaulter:blobs:reconcile')->weekly();
Schedule::command('vaulter:webhooks:prune')->weekly();
Schedule::command('vaulter:changes:prune')->daily();
```

## See also

- [Configuration reference](/reference/config) — `vaulter-media.gc.*`, `vaulter-drive.trash.*`, `vaulter-drive.uploads.*`, `webhooks.*`, `vaulter.modules.webhooks`
- [Events reference](/reference/events) — what the relay fans out
- [Facade & Manager](/reference/php-api) — the runtime API

## All commands (generated)

<!-- vaulter:commands-reference:start -->
| Command | Description | Synopsis |
|---|---|---|
| `vaulter:blobs:gc` | Garbage-collect unreferenced Vaulter blobs (two-phase, race-safe — H.3) | `[--grace [GRACE]] [--pending]` |
| `vaulter:blobs:reconcile` | Rebuild blob ref_count from edge counts and report dangling rows; optionally disk-walk for orphan objects (H.3) | `[--orphans] [--disk [DISK]] [--orphan-grace [ORPHAN-GRACE]] [--prune]` |
| `vaulter:changes:prune` | Delete drive change-journal entries past the retention window. | `[--days [DAYS]] [--drive [DRIVE]]` |
| `vaulter:changes:reset` | Expire every change-journal cursor of a drive, forcing its clients to resynchronize. | `<drive>` |
| `vaulter:commands:reference` | Generate the command table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:config:reference` | Generate the config key table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:doctor` | Diagnose the Vaulter setup of this host (read-only) | `[--production] [--json] [--check [CHECK]] [--sample [SAMPLE]] [--probe-storage]` |
| `vaulter:drives:purge` | Purge drives with status deleting: nodes, revisions and blob references, then the drive row. | `[--drive [DRIVE]] [--dry-run] [--sync]` |
| `vaulter:drives:recount` | Recount the used bytes of drives from their retained file revisions. | `[--drive [DRIVE]] [--dry-run]` |
| `vaulter:errors:reference` | Generate the problem-code reference in the docs. | `[--check] [--path [PATH]]` |
| `vaulter:events:reference` | Generate the event catalogue table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:idempotency:prune` | Delete expired idempotency receipts. | `[--limit [LIMIT]]` |
| `vaulter:install` | Publish Vaulter config, show the host settings to apply, optionally migrate and run the doctor | `[--middleware [MIDDLEWARE]] [--driver [DRIVER]] [--migrate] [--force]` |
| `vaulter:make:document-type` | Scaffold a Vaulter document type | `[--force] [--] <name>` |
| `vaulter:make:exporter` | Scaffold a Vaulter document exporter | `[--force] [--] <name>` |
| `vaulter:make:file-handler` | Scaffold a Vaulter file handler with a contract test | `[--force] [--] <name>` |
| `vaulter:make:permission-driver` | Scaffold a Vaulter permission driver with a contract test | `[--force] [--] <name>` |
| `vaulter:make:profile` | Scaffold a Vaulter profile provider (zero-migration config envelope) | `[--host [HOST]] [--no-grants] [--force] [--] <name>` |
| `vaulter:module:uninstall` | Plan (dry run) or perform the removal of a Vaulter module's schema | `[--force] [--purge] [--] <module>` |
| `vaulter:modules` | List the Vaulter modules, their activation and what they contribute | `[--json]` |
| `vaulter:outbox:prune` | Delete outbox events and deliveries past their retention without losing undelivered ones (D29). | — |
| `vaulter:relay` | Relay the outbox to its consumers: register, reconcile, deliver, hold (D29). | `[--consumer [CONSUMER]] [--limit [LIMIT]] [--once] [--max-seconds [MAX-SECONDS]]` |
| `vaulter:relay:replay` | Replay a range of the outbox to one consumer, or acknowledge its gap (D29). | `[--consumer [CONSUMER]] [--from [FROM]] [--to [TO]] [--ack-only]` |
| `vaulter:revisions:prune` | Delete non-current file revisions past their profile retention policy. | `[--drive [DRIVE]] [--dry-run]` |
| `vaulter:scan:reconcile` | Recover bounded scan intents, backfill discovery and committed upload cleanup. | `[--tenant [TENANT]] [--drive [DRIVE]] [--all-tenants] [--reason [REASON]] [--limit [LIMIT]] [--max-seconds [MAX-SECONDS]] [--dry-run] [--json]` |
| `vaulter:scan:rescan` | Activate scan policy or record bounded durable scan requests. | `[--tenant [TENANT]] [--drive [DRIVE]] [--all-tenants] [--reason [REASON]] [--revision [REVISION]] [--after [AFTER]] [--limit [LIMIT]] [--request-id [REQUEST-ID]] [--rescan] [--retry-failed] [--activate-policy] [--dry-run] [--json]` |
| `vaulter:trash:purge` | Permanently delete trashed nodes whose retention window has elapsed. | `[--days [DAYS]]` |
| `vaulter:uploads:sweep` | Abort and clean up stale resumable-upload sessions past their TTL (§2.8). | — |
| `vaulter:webhooks:prune` | Delete terminal webhook deliveries past the retention window. | `[--days [DAYS]] [--dry-run]` |
| `vaulter:webhooks:redeliver` | Send finished webhook deliveries again (same webhook-id). | `[--endpoint [ENDPOINT]] [--since [SINCE]] [--status [STATUS]] [--] [<delivery>]` |
| `vaulter:webhooks:retry` | Re-dispatch webhook deliveries whose backoff window has elapsed (§2.11). | — |
<!-- vaulter:commands-reference:end -->
