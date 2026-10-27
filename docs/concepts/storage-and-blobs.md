---
title: Storage & Blobs
description: How Vaulter stores file bytes — content-addressed blobs keyed by sha256, per-scope deduplication, the race-free two-phase garbage collector, and Flysystem disks.
outline: deep
---

# Storage & Blobs

Vaulter never copies file bytes into its own tables. Every uploaded file is stored
**once** as an immutable, content-addressed **blob** on a Flysystem disk, and the
database keeps only metadata: the `sha256`, the size, the disk, the scope-qualified
key, a lifecycle status, and the **pins** that keep the blob alive.

This is the **content-addressed store (CAS)**. It gives you transparent
deduplication, a clear separation between *bytes on disk* and *rows in the
database*, and a garbage collector that is safe to run while uploads are in flight.

::: info Where this lives
The CAS belongs to the package `axioma-studio/vaulter-media`: the implementation is in `Vaulter\Media\Cas\*`
(including `BlobLock`), the contracts in `Vaulter\Media\Contracts\Cas\*`, the row model is
`Vaulter\Media\Models\Cas\Blob`, the lifecycle enum is `Vaulter\Media\Enums\Cas\BlobStatus`, the tables are
`v_blobs` and `v_blob_references` (the pins), and configuration sits under `config/vaulter-media.php`
(`vaulter-media.models.blob`, `disk`, `dedup`, `gc`). Media knows no drive table: whoever keeps bytes alive pins them
through `BlobPins`.
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
BlobPins::pin()          ─► a row in v_blob_references that keeps the blob alive (liveness truth)
```

Three contracts split the work, and all three are swappable through `config('vaulter-media')`:

| Contract | Default | Responsibility |
|---|---|---|
| `Vaulter\Media\Contracts\Cas\BlobStore` | `Vaulter\Media\Cas\BlobStore` | High-level lifecycle: `commit` / `commitStaged` / `read`. Prepared and stream CAS entry points. Keeping a blob alive is not its job — that is `BlobPins`. |
| `Vaulter\Media\Contracts\Cas\ContentStore` | `Vaulter\Media\Cas\Sha256ContentStore` | Byte side: `stage` / `promote` / `get` / `exists` / `delete` / `keyFor`. Knows bytes, hashes and keys — never a `v_blobs` row. |
| `Vaulter\Media\Contracts\Cas\StorageDriver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | Thin Flysystem abstraction: `put` / `get` / `exists` / `delete` / `temporaryUrl` / `disk`. |

```php
// config/vaulter-media.php
'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,

```

All three are bound in the service provider and resolved through the container, so
the values are config-cache safe (FQCNs only, no closures). Point any of them at
your own class to change how bytes are staged, hashed, promoted, or stored — see
[Recipes › Custom storage driver](/guides/recipes).

## Bytes on disk, metadata in the database

A blob is just metadata. The `v_blobs` row (model `Vaulter\Media\Models\Cas\Blob`) carries:

| Column | Meaning |
|---|---|
| `sha256` | The lowercase hex content address (always recomputed server-side). |
| `size_bytes` | Authoritative size of the durable bytes. |
| `disk` | The Flysystem disk the canonical object lives on. |
| `storage_key` | The scope-qualified path of the object — `"<scope>/ab/<sha256>"`. |
| `status` | Lifecycle state (see [Blob lifecycle](#blob-lifecycle)). |
| `ref_count` | A **reconciled cache** of the live pin count — *not* the liveness truth. |
| `dedup_scope` | `tenant` \| `drive` \| `off` (`global` on blobs not yet repaired) — the boundary the address is unique within. |
| `scope_key` | Lossless `tenant:{id}`, `drive:{id}` or `off:{ulid}`; unique together with `sha256`. |
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

- `tenant` → `t-{id}`, or `d-{drive id}` when the drive has no tenant
- `drive` → `d-{id}`
- `off` → `o-{ulid}`, a scope of its own for every upload

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
`Vaulter\Media\Contracts\Cas\StagedObject`) exposes `path()`, `sha256()`, `sizeBytes()`,
`readStream()` and an idempotent `discard()` that removes the temp file once the
bytes are promoted (or the commit rolls back).

## Deduplication & `vaulter-media.dedup.scope`

The dedup scope decides the boundary a content address is unique within:

```php
// config/vaulter-media.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // tenant|drive|off
],
```

| Scope | Uniqueness | Effect |
|---|---|---|
| `tenant` | `UNIQUE (scope_key, sha256)` | One object per tenant — no cross-tenant existence leak; a drive without a tenant keeps to itself. **Default.** |
| `drive` | `UNIQUE (scope_key, sha256)` | One object per drive (`storage` is read as `drive`). |
| `off` | `UNIQUE (scope_key, sha256)` | Every upload is stored on its own. |

Deduplication never crosses a tenant (PLAN2.D49): an install-wide scope lets one tenant
learn that another stored a given file. A `global` setting is refused at boot with
`InvalidConfigurationException`. Upload responses and the
quota never depend on whether bytes deduplicated.

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
[Multi-tenancy](/concepts/tenancy) for how `vaulter-media.dedup.scope = tenant` interacts with
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
- **No synchronous `ref_count++`.** The liveness truth is the *pin* in
  `v_blob_references`; `ref_count` is a reconciled cache that may lag.

### Pinning and reading

A committed blob is kept alive by a **pin**: a row `(blob, owner_type, reference_key)`, unique on that triple,
written through the `Vaulter\Media\Contracts\Cas\BlobPins` contract by the module that keeps the bytes. The
`owner_type` is opaque and names the owner (`drive.revision_blob` for a file revision's link); the `reference_key`
is derived from the owner's own row. The owner pins in the **same transaction** that writes its row and unpins in
the transaction that deletes it, under the blob's `(scope, sha256)` lock:

```php
$pins->pin($blob, 'drive.revision_blob', $revisionId.':data:0');
$pins->unpin($blob, 'drive.revision_blob', $revisionId.':data:0'); // bytes are NOT deleted here — GC handles that
$stream = $blobStore->read($blob);                                 // resource|null over the canonical bytes
```

In the drive, `Vaulter\Drive\Content\RevisionBlobLinks` is the only writer of the revision↔blob link and its pin.
A pin to a tombstoned blob is accepted only while its bytes still exist (the blob is then committed again); a pin
to a deleting or deleted blob throws `BlobUnavailableException` (problem code `blob_unavailable`, 409 over REST) —
commit the bytes again and pin the returned blob. Pins outlive the owner's activation: switching the drive off,
or removing it from Composer, releases nothing, and `vaulter:doctor` (`media.blobs.pins`) names the owners that are
gone. Only the owner's own purge or rollback releases its pins (`vaulter:module:uninstall drive --force --purge`);
no generic command removes another module's pins. See the [`BlobPins` contract](/reference/php-api).

In the Drive, `IngestFileAction` prepares and hashes bytes through
`ContentStore::stage()` before entering its SQL transaction. Inside the transaction,
locks follow **drive → nodes by id → file aggregate → quota → CAS**. A revision
reads its previous head and `revision_count` from the locked file; its new number is
`revision_count + 1` and it reserves its full size: every retained revision counts
against the quota, without a dedup discount (PLAN2.D48).

`DriveStoreRepository::reserveBytes()` applies a conditional atomic UPDATE:
`used_bytes = used_bytes + delta` only when the quota is unlimited or the result
fits `quota_bytes`. A failed reservation throws `QuotaExceededException` and rolls
back all metadata. Copies reserve the sum of their subtree's heads in one UPDATE.
`used_bytes` includes the revisions of trashed files; deleting or pruning a revision and
purging a file free bytes.

The upload then calls `BlobStore::commitStaged($staged, $scope, $disk)`, creates the
file/revision/link (and its pin) and updates the head. `commitStaged()` leaves the staged object
intact. Its caller discards it in `finally`, after completion or failure, so SQL
serialization/deadlock retries (up to three attempts) reuse the same prepared bytes.
`commit($stream, $scope, $disk)` remains the convenience stage/commitStaged/discard
entry point. Custom `BlobStore` implementations must implement `commitStaged()`.

Promotion is an idempotent storage operation outside SQL rollback: **stage →
promote → metadata commit**. A failure after promotion can leave canonical bytes
without a blob row. Reconciliation collects these orphans after the configured
grace period under the same CAS lock, preserving objects referenced by winners.

## Delivering bytes over HTTP

Reading a blob is internal. Bytes reach an HTTP client only through the REST v1
[file delivery](/reference/rest-api#file-delivery) point, which checks access, tenant, profile
and scan state again on **every** request and pins the **exact revision** asked for (the head,
or an explicit revision). `vaulter-drive.downloads.delivery_mode` picks how:

| Mode | What the client gets | Revocation |
|---|---|---|
| `strict` (default) | the bytes, streamed by the application, `Cache-Control: private, no-store` | every new request is checked; a stream already started is not cut |
| `direct_signed` (opt-in) | a `302` to a URL your `Vaulter\Media\Contracts\Cas\DirectContentUrlSigner` signs for that exact object version, valid `vaulter-drive.downloads.direct_ttl_seconds` (1–300) | a URL already issued stays valid until it expires — revoking access does not cancel it |

`direct_signed` needs a signer bound in the container; the signer receives the immutable content
identity (blob, storage key, storage version, sha256, size) and must sign exactly that. Choose it
only if a bounded revocation window is acceptable for your data. A revision whose scan state is
not clean answers `423 content_unavailable` in both modes before any byte or URL; public share
downloads always stream. Byte ranges are not served in 1.0 (`Accept-Ranges: none`).

Revision downloads authorize View and Download before selecting the revision in both modes.
Only committed or superseded revisions can be downloaded. A caller without Download receives
`403 permission_denied` for either an existing or an unknown revision id, without a signed URL.

## Blob lifecycle

`Vaulter\Media\Enums\Cas\BlobStatus` is the state machine:

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

When you trash and purge a file, its revision links go and their pins are released. A blob with no remaining
pins is *garbage* — but deleting its bytes safely, while uploads may be
resurrecting that exact `sha256`, is the hard part. Vaulter's GC
(`Vaulter\Media\Cas\BlobGarbageCollector`) solves it in two phases, **ACL-blind** and run
as the system (a node a user cannot see still pins its blob). It reads only the media tables — `v_blobs` and
`v_blob_references` — never a table of the drive.

### Phase 1 — mark

Scan `committed ∧ ref_count <= 0` (the `scopeGarbageCollectable` query). For each
candidate, under the per-`(scope, sha256)` lock, **re-verify** that:

- the status is still `Committed` (the cheap scan may be stale), and
- no pin exists in `v_blob_references`.

If both hold, `tombstone()` sets `status = Tombstoned` and `delete_after_at = now +
grace`. The grace deadline is armed *here*, so the sweep can never delete within the
grace window.

### Phase 2 — sweep

Take `tombstoned` blobs past their grace (`scopeSweepable`). Under the lock,
re-verify the blob is still `Tombstoned`, still has no pin, and is past
`delete_after_at`. Only then: `markDeleting` → physical delete of the
scope-qualified object → `markDeleted` (an idempotent tombstone; a missing object is
fine).

::: tip The safety invariant
Every transaction that changes a content address's liveness — upload-commit,
GC-mark, GC-sweep — takes the per-`(scope, sha256)` lock as its **first statement**
and decides from rows read *after* it. That is what makes online GC safe: a commit
that wins the lock resurrects a tombstoned blob *before* the sweep can see it; a
sweep that wins the lock re-checks pin existence *before* deleting.
:::

Per database driver (`Vaulter\Media\Cas\BlobLock`):

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
grace window (`first_seen_at < now − grace`) that carries no pin. Its possibly
partial object is deleted and the row finalised as a deleted tombstone, under the
lock so a slow-but-live commit is never stolen.

### Running the collector

```bash
# Both phases (mark + sweep), grace from config('vaulter-media.gc.grace_hours'):
php artisan vaulter:blobs:gc

# Override the grace window (floored at 1h):
php artisan vaulter:blobs:gc --grace=48

# Also clear crashed-staging PENDING rows:
php artisan vaulter:blobs:gc --pending
```

The command prints a per-phase tally — `Tombstoned X, swept Y, skipped Z (grace Nh)`.

```php
// config/vaulter-media.php
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
resurrect first. It is floored at 1h by `MediaConfig::gcGraceHours()` regardless of
config — never set it to zero expecting an immediate purge.
:::

::: details Schedule it yourself
`vaulter:blobs:gc` and `vaulter:blobs:reconcile` are registered by the package but
**not** auto-scheduled — Vaulter does not touch your scheduler. Add them where you
schedule maintenance, e.g. in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:blobs:reconcile')->daily();
Schedule::command('vaulter:blobs:gc --pending')->dailyAt('03:00');
```

Run reconcile *before* GC so `ref_count` reflects reality when the mark scan reads it.
:::

See [Recipes › Blob GC & reconcile cron](/guides/recipes) for a full operations
recipe.

## Reconciliation: rebuilding the `ref_count` cache

`ref_count` is a cache, not the truth, so it drifts. `vaulter:blobs:reconcile`
(backed by `Vaulter\Media\Cas\BlobReconciler`) rebuilds it from the
`v_blob_references` pin counts — the real liveness source — and reports
integrity hazards. It **never deletes anything**.

```bash
php artisan vaulter:blobs:reconcile
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
php artisan vaulter:blobs:reconcile --orphans

# Scan a specific disk and override the orphan grace window (hours, floored at 1h):
php artisan vaulter:blobs:reconcile --orphans --disk=s3 --orphan-grace=48

# DELETE the orphans found — PostgreSQL/MySQL only (refused on SQLite; see below):
php artisan vaulter:blobs:reconcile --orphans --prune
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
The orphan walk uses `config('vaulter-media.gc.orphan_grace_hours')`
(env `VAULTER_ORPHAN_GRACE_HOURS`), which **defaults to `vaulter-media.gc.grace_hours`** (24) and is
floored at 1h. Keep it ≥ your worst-case multipart-commit latency for the same reason
the GC grace must be generous: an in-flight resumable upload can promote its bytes long
before its row commits, and a fresh object must never be mistaken for an orphan.
:::

## Flysystem disks: `local`, S3, and per-storage

Blobs live on a standard Laravel Flysystem disk. The disk for a given blob is
resolved per call by `FlysystemStorageDriver::disk()`: a blob's own `disk` column
(set from the owning `v_drives.disk`) wins, otherwise the install default:

```php
// config/vaulter.php
'storage' => [
    'default_disk' => env('VAULTER_DISK', 'local'),
],
```

Because it is just a Flysystem disk, any driver works for the CAS — `local`, `s3`, or a custom
one (resumable-upload *completion* is the exception: it needs a local staging disk, see [Uploads](/guides/uploads)) — configured in your app's `config/filesystems.php`. The CAS only does stream
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

- [Configuration](/guides/configuration) — every `cas` / `dedup` / `gc` / `storage`
  key in one place.
- [Multi-tenancy](/concepts/tenancy) — how host-owned tenancy feeds `tenant_id`
  into blobs and why `vaulter-media.dedup.scope = tenant` matters.
- [Recipes › Custom storage driver](/guides/recipes) — swap the disk / drive
  a custom `ContentStore`.
- [Recipes › Blob GC & reconcile cron](/guides/recipes) — production operations.

## The CAS commit saga

The CAS saga is stage → SHA-256 → `cas:{scope_key}:{sha256}` lock → row lookup or
resurrection → conditional promotion → pin → SQL commit. SQL rollback after
promotion can leave an orphan. Reconcile recognizes both key layouts, waits for
`orphan_grace_hours`, and rechecks row absence under the same CAS lock before
removing bytes. GC uses the blob row's `scope_key` with that identical namespace.
