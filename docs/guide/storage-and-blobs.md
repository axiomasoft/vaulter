---
title: Storage & Blobs
description: How Vaulter stores file bytes — content-addressed blobs keyed by sha256, per-scope deduplication, the race-free two-phase garbage collector, and Flysystem disks.
outline: deep
---

# Storage & Blobs

Vaulter never copies file bytes into its own tables. Every uploaded file is stored
**once** as an immutable, content-addressed **blob** on a Flysystem disk, and the
database keeps only metadata: the `sha256`, the size, the disk, the scope-qualified
key, a lifecycle status, and the edges that pin the blob to file versions.

This is the **content-addressed store (CAS)**. It gives you transparent
deduplication, a clear separation between *bytes on disk* and *rows in the
database*, and a garbage collector that is safe to run while uploads are in flight.

::: info Where this lives
The CAS implementation is in `Vaulter\Media\Cas\*` (package `axioma-studio/vaulter-media`;
the host-facing `BlobLock` stays at `Vaulter\Cas\BlobLock` in core), the row model is
`Vaulter\Models\Cas\Blob`, the lifecycle enum is `Vaulter\Enums\Cas\BlobStatus`, and
configuration sits under the `cas`, `dedup`, `gc` and `storage` keys of
`config/vaulter.php`.
:::

## The mental model

```
upload stream
     │
     ▼
ContentStore::stage()   ─► local seekable temp file, sha256 recomputed from THOSE bytes
     │
     ▼
BlobStore::commitStaged() ─► one locked transaction: find-or-create v_blobs row by (scope_key, sha256)
     │                       └─ dedup hit?  reuse the row, skip the physical write
     ▼
ContentStore::promote() ─► write staged bytes to "<scope>/ab/<sha256>" on the disk
     │
     ▼
v_file_version_blobs    ─► the EDGE that pins the blob alive (liveness truth)
```

Three contracts split the work, and all three are swappable through `config('vaulter.cas')`:

| Contract | Default | Responsibility |
|---|---|---|
| `Vaulter\Contracts\Cas\BlobStore` | `Vaulter\Media\Cas\BlobStore` | High-level lifecycle: `commit` / `commitStaged` / `attach` / `detach` / `read`. Prepared and stream CAS entry points. |
| `Vaulter\Contracts\Cas\ContentStore` | `Vaulter\Media\Cas\Sha256ContentStore` | Byte side: `stage` / `promote` / `get` / `exists` / `delete` / `keyFor`. Knows bytes, hashes and keys — never a `v_blobs` row. |
| `Vaulter\Contracts\Cas\StorageDriver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | Thin Flysystem abstraction: `put` / `get` / `exists` / `delete` / `temporaryUrl` / `disk`. |

```php
// config/vaulter.php
'cas' => [
    'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
    'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
    'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,
],
```

All three are bound in the service provider and resolved through the container, so
the values are config-cache safe (FQCNs only, no closures). Point any of them at
your own class to change how bytes are staged, hashed, promoted, or stored — see
[Recipes › Custom storage driver](/guide/recipes).

## Bytes on disk, metadata in the database

A blob is just metadata. The `v_blobs` row (model `Vaulter\Models\Cas\Blob`) carries:

| Column | Meaning |
|---|---|
| `sha256` | The lowercase hex content address (always recomputed server-side). |
| `size_bytes` | Authoritative size of the durable bytes. |
| `disk` | The Flysystem disk the canonical object lives on. |
| `storage_key` | The scope-qualified path of the object — `"<scope>/ab/<sha256>"`. |
| `status` | Lifecycle state (see [Blob lifecycle](#blob-lifecycle)). |
| `ref_count` | A **reconciled cache** of the live edge count — *not* the liveness truth. |
| `dedup_scope` | `global` \| `tenant` \| `storage` — the boundary the address is unique within. |
| `scope_key` | Lossless `global`, `tenant:{id}` or `drive:{id}`; unique together with `sha256`. |
| `tenant_id` / `storage_id` | Informational scope IDs; `scope_key` owns deduplication. |
| `first_seen_at` / `committed_at` / `delete_after_at` | Staging, commit, and GC-grace timestamps. |

The bytes themselves never appear in any column. The model timestamps are disabled
(`public $timestamps = false`) — the CAS manages its own three timestamps.

### The storage key is scope-qualified

`ContentStore::keyFor(BlobScope $scope, string $sha256)` uses the first two digest
characters as one shard: `{scope-segment}/{sha[0:2]}/{sha}`. Historical scopes keep
`{legacy-segment}/{sha[0:2]}/{sha[2:4]}/{sha}`. Migration preserves their stored paths;
reads always use the blob row's `storage_key` and `disk`.

::: warning Why the scope segment matters
Under per-scope dedup, two scopes may legitimately hold the same `sha256`. Without
the scope segment baked into the key, *one* scope's garbage collector could delete
*another* scope's live bytes. The scope segment guarantees distinct scopes never
resolve to the same physical object.
:::

The scope segment is derived from the dedup scope:

- `global` → `g`
- `tenant` → `t-{id}`, or `g` when there is no tenant
- `storage` → `d-{id}`

`BlobScope` preserves string IDs, including UUIDs, ULIDs and leading zeroes.
Identifiers contain 1–96 bytes. An ID matching `[A-Za-z0-9_-]{1,64}` is used verbatim;
other IDs use `~` followed by unpadded base64url. `fromSegment()` reverses this
encoding and rejects noncanonical segments. Legacy factories retain `global`,
`s<id>`, `t<id>`, `snull` and `tnull` paths.

## Hashing: the staged file, never the live stream

`Sha256ContentStore::stage()` drains the incoming stream into a **local, seekable
temp file** (in 1 MiB chunks) and computes the `sha256` from *those durable bytes*.

::: danger Vaulter never trusts a client-supplied hash
The content address is **always** recomputed server-side from the staged file — a
lying client `sha256`, a resumable-upload `expected_sha256`, or an S3 multipart
`ETag` are never used as the content address. Hashing happens *outside* the database
transaction, so hashing a multi-GB upload never holds the row/advisory lock.
:::

The staged object (`Vaulter\Media\Cas\StagedTempObject`, implementing
`Vaulter\Contracts\Cas\StagedObject`) exposes `path()`, `sha256()`, `sizeBytes()`,
`readStream()` and an idempotent `discard()` that removes the temp file once the
bytes are promoted (or the commit rolls back).

## Deduplication & `dedup.scope`

The dedup scope decides the boundary a content address is unique within:

```php
// config/vaulter.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|storage
],
```

| Scope | Uniqueness | Effect |
|---|---|---|
| `global` | `UNIQUE (scope_key, sha256)` | One physical object per `sha256` across the whole install. |
| `tenant` | `UNIQUE (scope_key, sha256)` | One object per tenant — no cross-tenant existence leak. **Default.** |
| `storage` | `UNIQUE (scope_key, sha256)` | One object per storage container. |

`BlobScope::forUpload()` selects a `scope_key` from this setting. The schema always
uses `UNIQUE(scope_key, sha256)`; scope changes do not alter that index shape.
`UNIQUE(storage_key)` remains an independent physical-address guard.

::: tip The UNIQUE index is the portable race backbone
When two requests upload identical bytes concurrently, the `UNIQUE (scope_key, sha256)`
constraint is the cross-database backbone: one `INSERT` wins, the loser catches the
unique violation, re-`SELECT`s the winning row, and reuses it. This holds on
PostgreSQL, MySQL and SQLite even where the advisory lock below is a no-op.
:::

::: warning Choosing a scope is a one-way door
The scope is stored in every blob's `scope_key` and `storage_key`. Pick
it at install time. `tenant` is the safe default for multi-tenant apps because it
prevents one tenant from learning another tenant holds a given file (an existence
leak), at the cost of storing identical bytes once per tenant. See
[Multi-tenancy](/guide/multi-tenancy) for how `dedup.scope = tenant` interacts with
host-owned tenancy.
:::

## The commit flow

`BlobStore::commit()` is the single CAS entry point. It runs **one** transaction
whose **first statement** is the per-`(scope, sha256)` lock, so every liveness
decision is made from rows read *after* the lock:

```php
// Vaulter\Media\Cas\BlobStore::commit() — simplified
$staged = $this->content->stage($stream);          // 1. stage + hash OUTSIDE the tx
$key    = $this->content->keyFor($scope, $staged->sha256());

$this->database->transaction(function () use (…) {
    $this->database->advisoryLock('cas:'.$scope->key().':'.$staged->sha256());          // 2. lock = statement #1

    $result = $this->blobs->findOrCreate(…);                  // 3. find-or-create by dedup key
                                                              //    (a tombstoned match is RESURRECTED)

    if ($result['created'] || !$this->content->exists($key)) { // 4. promote bytes only when needed
        $this->content->promote($staged, $key, $disk);
    }

    return $result['blob'];
});
```

Key properties (all from `Vaulter\Media\Cas\BlobStore`):

- **Staging happens outside the transaction.** Only the liveness decision is locked.
- **A dedup hit skips the physical write.** Bytes are promoted only when the row is
  newly created (or the canonical object is somehow missing — `promote()` is
  idempotent and conditional, so a resurrected row whose object vanished is
  re-promoted safely).
- **No synchronous `ref_count++`.** The liveness truth is the *edge* in
  `v_file_version_blobs`; `ref_count` is a reconciled cache that may lag.

### Attaching and reading

A committed blob is pinned alive by a **version↔blob edge**:

```php
$blobStore->attach($fileVersion, $blob, ['role' => 'data', 'ordinal' => 0]);
$blobStore->detach($fileVersion, $blob);   // bytes are NOT deleted here — GC handles that
$stream = $blobStore->read($blob);         // resource|null over the canonical bytes
```

In the Drive, `UploadFileAction` prepares and hashes bytes through
`ContentStore::stage()` before entering its SQL transaction. Inside the transaction,
locks follow **drive → nodes by id → file aggregate → quota → CAS**. A revision
reads its previous head and `version_count` from the locked file; its new number is
`version_count + 1` and its quota delta is new size minus that head's size.

`StorageStoreRepository::reserveBytes()` applies a conditional atomic UPDATE:
`used_bytes = used_bytes + delta` only when the quota is unlimited or the result
fits `quota_bytes`. A failed reservation throws `QuotaExceededException` and rolls
back all metadata. Copies reserve the sum of their subtree's heads in one UPDATE.
`used_bytes` includes heads in the trash; only purge frees those bytes.

The upload then calls `BlobStore::commitStaged($staged, $scope, $disk)`, creates the
file/version/edge and updates the head. `commitStaged()` leaves the staged object
intact. Its caller discards it in `finally`, after completion or failure, so SQL
serialization/deadlock retries (up to three attempts) reuse the same prepared bytes.
`commit($stream, $scope, $disk)` remains the convenience stage/commitStaged/discard
entry point. Custom `BlobStore` implementations must implement `commitStaged()`.

Promotion is an idempotent storage operation outside SQL rollback: **stage →
promote → metadata commit**. A failure after promotion can leave canonical bytes
without a blob row. Reconciliation collects these orphans after the configured
grace period under the same CAS lock, preserving objects referenced by winners.

## Blob lifecycle

`Vaulter\Enums\Cas\BlobStatus` is the state machine:

```
Pending → Committed → Tombstoned → Deleting → Deleted
              ↑____________|
             (resurrection)
```

| State | Meaning |
|---|---|
| `Pending` | Row exists, bytes staged but not yet durable+verified. A crashed staging leaves a `Pending` row swept on timeout. |
| `Committed` | Bytes durable, `sha256` verified — the live state. |
| `Tombstoned` | GC marked it unreferenced; `delete_after_at` carries the grace deadline. A concurrent commit **resurrects** it back to `Committed`. |
| `Deleting` | The sweep claimed it; the physical delete is in flight. |
| `Deleted` | Bytes gone; the row is kept as an idempotent tombstone so a re-run of the sweep is a no-op. |

The enum enforces legal transitions via `allowedTransitions()` / `canTransitionTo()`,
and `isLive()` returns true for `Pending` and `Committed` (both pin their bytes).

## Garbage collection (two-phase, race-free)

When you trash and purge a file, its version edges detach. A blob with no remaining
edges is *garbage* — but deleting its bytes safely, while uploads may be
resurrecting that exact `sha256`, is the hard part. Vaulter's GC
(`Vaulter\Media\Cas\BlobGarbageCollector`) solves it in two phases, **ACL-blind** and run
as the system (a node a user cannot see still pins its blob).

### Phase 1 — mark

Scan `committed ∧ ref_count <= 0` (the `scopeGarbageCollectable` query). For each
candidate, under the per-`(scope, sha256)` lock, **re-verify** that:

- the status is still `Committed` (the cheap scan may be stale), and
- no live edge exists in `v_file_version_blobs`.

If both hold, `tombstone()` sets `status = Tombstoned` and `delete_after_at = now +
grace`. The grace deadline is armed *here*, so the sweep can never delete within the
grace window.

### Phase 2 — sweep

Take `tombstoned` blobs past their grace (`scopeSweepable`). Under the lock,
re-verify the blob is still `Tombstoned`, still has no live edge, and is past
`delete_after_at`. Only then: `markDeleting` → physical delete of the
scope-qualified object → `markDeleted` (an idempotent tombstone; a missing object is
fine).

::: tip The safety invariant
Every transaction that changes a content address's liveness — upload-commit,
GC-mark, GC-sweep — takes the per-`(scope, sha256)` lock as its **first statement**
and decides from rows read *after* it. That is what makes online GC safe: a commit
that wins the lock resurrects a tombstoned blob *before* the sweep can see it; a
sweep that wins the lock re-checks edge existence *before* deleting.
:::

Per database driver (`Vaulter\Cas\BlobLock`):

- **PostgreSQL** — `pg_advisory_xact_lock(hashtext(scope||sha256))`, a real mutex
  held for the whole transaction. The sweep additionally runs `SERIALIZABLE` with
  retry-on-`40001` (up to 5 attempts).
- **MySQL / MariaDB** — no transaction-advisory lock that survives like PG's, so the
  store relies on `SELECT … FOR UPDATE` on the blob row; the advisory helper is a
  no-op.
- **SQLite** — single-writer; the transaction itself serialises.

The `UNIQUE (scope_key, sha256)` constraint is the portable backbone that holds on all
three even when the advisory lock is a no-op.

### Pending sweep

`sweepPending()` clears rows from a crashed staging — a `Pending` row older than the
grace window (`first_seen_at < now − grace`) that carries no edge. Its possibly
partial object is deleted and the row finalised as a deleted tombstone, under the
lock so a slow-but-live commit is never stolen.

### Running the collector

```bash
# Both phases (mark + sweep), grace from config('vaulter.gc.grace_hours'):
php artisan vaulter:gc-blobs

# Override the grace window (floored at 1h):
php artisan vaulter:gc-blobs --grace=48

# Also clear crashed-staging PENDING rows:
php artisan vaulter:gc-blobs --pending
```

The command prints a per-phase tally — `Tombstoned X, swept Y, skipped Z (grace Nh)`.

```php
// config/vaulter.php
'gc' => [
    'grace_hours'        => env('VAULTER_GC_GRACE_HOURS', 24),
    // The separate, generally more generous window for the orphan disk-walk
    // (see Reconciliation below). Defaults to gc.grace_hours, floored at 1h.
    'orphan_grace_hours' => env('VAULTER_ORPHAN_GRACE_HOURS', env('VAULTER_GC_GRACE_HOURS', 24)),
],
```

::: warning The grace window must outlast your slowest upload
A tombstoned blob can be resurrected by an in-flight upload of the same `sha256`. The
grace must be **≥ the worst-case multipart-commit latency** so an upload can
resurrect first. It is floored at 1h by `Config::gcGraceHours()` regardless of
config — never set it to zero expecting an immediate purge.
:::

::: details Schedule it yourself
`vaulter:gc-blobs` and `vaulter:reconcile-blobs` are registered by the package but
**not** auto-scheduled — Vaulter does not touch your scheduler. Add them where you
schedule maintenance, e.g. in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:reconcile-blobs')->daily();
Schedule::command('vaulter:gc-blobs --pending')->dailyAt('03:00');
```

Run reconcile *before* GC so `ref_count` reflects reality when the mark scan reads it.
:::

See [Recipes › Blob GC & reconcile cron](/guide/recipes) for a full operations
recipe.

## Reconciliation: rebuilding the `ref_count` cache

`ref_count` is a cache, not the truth, so it drifts. `vaulter:reconcile-blobs`
(backed by `Vaulter\Media\Cas\BlobReconciler`) rebuilds it from the
`v_file_version_blobs` edge counts — the real liveness source — and reports
integrity hazards. It **never deletes anything**.

```bash
php artisan vaulter:reconcile-blobs
```

It walks every blob, rewrites any stale `ref_count`, and flags **dangling rows** — a
`Committed` blob whose canonical object is *missing on disk* (a half-promoted commit
or a botched manual delete). The command exits non-zero and warns with the offending
blob keys:

```
Reconciled 1240 blobs, corrected 3 ref_count caches.
Dangling rows (committed blob, missing object): 01J…ABC, 01J…XYZ
```

### Orphan objects: the disk walk

A dangling row is one of two inverse integrity hazards; the other is the **orphan
object** — bytes on disk that match the committed CAS key layout but have **no blob
row at all** (a commit that promoted its bytes then rolled the row back, or leaked
bytes). Detecting it requires walking the *disk*, the inverse of the row-side
reconcile above. The `--orphans` flag adds that disk-walk to the same command:

```bash
# Detect orphan objects (report-only) in addition to the row-side reconcile:
php artisan vaulter:reconcile-blobs --orphans

# Scan a specific disk and override the orphan grace window (hours, floored at 1h):
php artisan vaulter:reconcile-blobs --orphans --disk=s3 --orphan-grace=48

# DELETE the orphans found — PostgreSQL/MySQL only (refused on SQLite; see below):
php artisan vaulter:reconcile-blobs --orphans --prune
```

It prints its own line next to the reconcile tally:

```
Orphan walk (report-only): scanned 1240, found 2, pruned 0, skipped 1238 (disk s3, grace 24h).
Orphan objects (bytes with no blob row): t42/ab/cd/abcd…ef, t42/12/34/1234…99
```

::: warning Report-only by default — and the run FAILS when it finds orphans
Without `--prune` the walk only **detects and reports** orphans (a safe dry run on
every driver). Like the dangling-row check, a report-only run that finds any orphan
**exits non-zero** so cron/CI sees the hazard. Only a `--prune` run that actually
deleted them exits success.
:::

The sweep is built to *only ever* touch genuine canonical orphans. Its guards, in
order:

1. **Exact CAS-key layout, round-trip verified.** A path is a candidate only when it
   exactly matches `"<scope>/<a>/<b>/<sha256>"` *and* `keyFor(scope, sha256)`
   re-derives the **same** path — so the shard bytes are the real shard of that
   digest. Staging/tmp parts, resumable-upload fragments, foreign files and any
   mis-sharded path never match and are **never** considered for deletion.
2. **Any blob row means owned.** A matching object that has *any* `v_blobs` row (any
   status, looked up disk-agnostically since `storage_key` is globally unique) is
   owned, not an orphan.
3. **The orphan-grace window.** A row-less object is a deletable orphan only once it
   is **older than the orphan grace** — a just-promoted object may be a
   commit-in-progress whose row is not yet durable. Anything younger than the grace
   (or whose age the disk can't report) is skipped, never deleted.
4. **Locked recheck before delete.** A `--prune` delete happens under the same
   per-`(scope, sha256)` lock the GC uses, re-checking both row-absence and the grace
   *after* taking the lock, then deletes idempotently — so a commit that just created
   the owning row can never have its bytes swept out from under it.

::: danger `--prune` is refused on SQLite — PostgreSQL/MySQL only
The prune deletes bytes under a **write-less** critical section, which only serialises
against a concurrent commit on a driver whose lock orders a no-row-write section
(PostgreSQL advisory xact lock; MySQL `FOR UPDATE`). On **SQLite** that ordering never
engages — a concurrent commit could insert the owning row between the recheck and the
delete — so pruning is **refused** there (the command warns and degrades to
report-only). **Detection works on every driver**; only the destructive `--prune`
requires PostgreSQL/MySQL. Run report-only locally, or prune against the production DB.
:::

::: info A disk that can't be enumerated is skipped, not failed
The disk-walk needs a CAS content store that can list the disk (a `ListableContentStore`).
If the active store does **not** implement it, the walk reports `"listing unsupported"`,
scans nothing, and exits cleanly. A listable store over a backend that returns no entries
(for example a non-`FilesystemAdapter` disk that can't be enumerated) simply scans **zero**
objects — equally safe: nothing is enumerated, so nothing is deleted. Either way the
row-side reconcile result still stands.
:::

::: details The orphan grace is separate from the GC grace
The orphan walk uses `config('vaulter.gc.orphan_grace_hours')`
(env `VAULTER_ORPHAN_GRACE_HOURS`), which **defaults to `gc.grace_hours`** (24) and is
floored at 1h. Keep it ≥ your worst-case multipart-commit latency for the same reason
the GC grace must be generous: an in-flight resumable upload can promote its bytes long
before its row commits, and a fresh object must never be mistaken for an orphan.
:::

## Flysystem disks: `local`, S3, and per-storage

Blobs live on a standard Laravel Flysystem disk. The disk for a given blob is
resolved per call by `FlysystemStorageDriver::disk()`: a blob's own `disk` column
(set from the owning `v_storages.disk`) wins, otherwise the install default:

```php
// config/vaulter.php
'storage' => [
    'default_disk' => env('VAULTER_DISK', 'local'),
],
```

Because it is just a Flysystem disk, any driver works — `local`, `s3`, or a custom
one — configured in your app's `config/filesystems.php`. The CAS only does stream
writes plus a few object-store niceties that degrade gracefully:

- **Conditional create.** On an object store, `put()` with `ifNoneMatch` maps to
  `If-None-Match: *` so a concurrent uploader of identical bytes cannot clobber; a
  `412`/`409` is surfaced as a `false` return (re-`SELECT` + reuse), not an
  exception. On `local`/SQLite-test there is no conditional PUT, so it degrades to a
  best-effort `exists()` guard — the per-`(scope, sha256)` lock is the real
  serialiser there.
- **Server-side checksum.** On S3, `promote()` attaches
  `x-amz-checksum-sha256` (base64 of the raw digest) so S3 verifies the staged bytes;
  the local driver ignores it, so passing it is config-aware and safe.
- **Signed URLs.** `temporaryUrl()` mints a short-lived signed URL only on disks that
  support it (`providesTemporaryUrls()`); the `local`/SQLite-test disk returns
  `null` rather than throwing.

::: danger Keep the CAS bucket UNVERSIONED
The canonical CAS bucket must **not** use S3 lifecycle/versioning as a
blob-deletion mechanism. Vaulter's two-phase GC is the deletion authority; an S3
lifecycle rule deleting blobs out from under it will produce dangling rows. (A
lifecycle `AbortIncompleteMultipartUpload` rule for *resumable uploads* is a
different, recommended backstop — see the `uploads` config.)
:::

## See also

- [Configuration](/guide/configuration) — every `cas` / `dedup` / `gc` / `storage`
  key in one place.
- [Multi-tenancy](/guide/multi-tenancy) — how host-owned tenancy feeds `tenant_id`
  into blobs and why `dedup.scope = tenant` matters.
- [Recipes › Custom storage driver](/guide/recipes) — swap the disk / drive
  a custom `ContentStore`.
- [Recipes › Blob GC & reconcile cron](/guide/recipes) — production operations.

## Repairing historical mixed scopes

Migration №7 fills `scope_key` from historical IDs without moving any object.
Blobs previously committed without a numeric storage or tenant ID become
`legacy:snull` or `legacy:tnull`. Inspect them with
`php artisan vaulter:blobs:repair-scopes --dry-run`, then apply with `--execute`.
The report shows blob, digest, source scope, storage, edge count, target and action.
Unreferenced blobs and SHA-256 mismatches are reported without changing their bytes
or references. Each target runs in its own `VaulterDatabase` transaction: lock the
affected trees and both CAS addresses, verify source bytes, copy to the target,
verify target bytes, redirect revision edges and the legacy `blob_id`, and rebuild
ref-count caches. Existing target blobs retain their authoritative disk/path.
Old objects remain until GC. Interrupted repairs resume without duplicate moves;
successes append JSON to `storage/logs/vaulter-repair-scopes.log`.

The CAS saga is stage → SHA-256 → `cas:{scope_key}:{sha256}` lock → row lookup or
resurrection → conditional promotion → edge → SQL commit. SQL rollback after
promotion can leave an orphan. Reconcile recognizes both key layouts, waits for
`orphan_grace_hours`, and rechecks row absence under the same CAS lock before
removing bytes. GC uses the blob row's `scope_key` with that identical namespace.
