---
title: Uploads
description: Resumable uploads — the tus.io core and S3-style multipart protocols (completion needs a local staging disk), and a direct one-shot path, all funnelling through the same race-free CAS commit.
outline: deep
---

# Uploads

Large files upload **resumably**: a long upload survives a dropped connection, a page reload,
even a different client picking up where the last one left off. Vaulter ships two resumable
transports plus a one-shot direct path, and every one of them funnels the assembled bytes
through the **same race-free `BlobStore.commitStaged`** — so dedup, the race-free blob state
machine and quota all apply, and the `sha256` is **recomputed server-side** from the assembled
bytes (the client `sha256` / S3 ETag is **never** the content address).

::: info H.3 invariant
The server never trusts a client-supplied hash. A declared `expected_sha256` is an integrity
*hint*, not the content address — `ContentStore.stage` hashes the assembled bytes before SQL commit.
:::

## Atomic quota and revisions

A one-shot upload prepares its bytes once before the commit transaction (a resumable
`complete()` is the exception, see [Transaction length of `complete()`](#transaction-length-of-complete)).
The transaction locks drive → nodes by ascending id → file → quota → CAS. It reserves the
full size of the new revision with a conditional UPDATE, so simultaneous writers cannot exceed
`quota_bytes`. An unlimited quota is `null`; an exact-boundary reservation succeeds.
The preliminary check is only a fast rejection; the conditional UPDATE decides.

The quota is **logical** (PLAN2.D48): `used_bytes` is the sum of `size_bytes` of every
retained revision of every file of the drive, trash included, with no discount for
deduplicated bytes — two identical uploads use twice their size. A new revision allocates
`revision_count + 1` from the locked file and adds its full size, because the previous head
stays as a retained revision. Restoring a revision copies it forward as a new revision and
adds its size again; a copy adds the size of the head it copies. Deleting a revision,
pruning by the retention policy and purging a file release the bytes. A drive whose usage
ends up above its quota (a lowered quota, the upgrade recount) keeps reading, deleting and
purging; only growth is refused.

## Revision retention

Every revision of a file stays until it is deleted, pruned or the file is purged. The
drive profile's `RevisionRetentionPolicy` decides what pruning removes:

<!-- snippet: host -->

```php
use Vaulter\Drive\Profiles\DrivePolicyBuilder;
use Vaulter\Drive\Profiles\RevisionRetentionPolicy;

$profile->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy->revisionRetention(new RevisionRetentionPolicy(
    maxNoncurrentRevisions: 100, // newest non-current revisions kept per file; null = no count limit
    maxNoncurrentAgeDays: null,  // delete non-current revisions superseded longer ago; null = no age limit
    minNoncurrentAgeDays: 7,     // never prune a revision superseded more recently than this
)));
// Shorthand: keep at most 10 revisions per file, the head included.
$profile->revisionLimit(10);
```

Protection order: the head is never pruned, then pinned revisions, then revisions superseded
less than `minNoncurrentAgeDays` ago; of the rest, everything past the newest
`maxNoncurrentRevisions` unpinned non-current revisions and everything older than
`maxNoncurrentAgeDays` goes. Pinned revisions do not count against the limit. The age counts
from `superseded_at`, the moment a revision stopped being the head.

Pruning runs as `php artisan vaulter:revisions:prune [--drive=ID ...] [--dry-run]` (system actor
`revision-retention`, files in batches of 500) or as the queued
`Vaulter\Drive\Jobs\PruneFileRevisionsJob` for one drive. Schedule it daily, like
`vaulter:trash:purge`:

<!-- snippet: host -->

```php
Schedule::command('vaulter:revisions:prune')->daily();
```

Single revisions are managed through the file API:

<!-- snippet: host -->

```php
$drive->files()->setRevisionRetention($file, $revision, keepForever: true); // Edit: pin / unpin
$drive->files()->deleteRevision($file, $revision);                          // Manage: delete now
```

`deleteRevision` refuses the head (409 `cannot_delete_head_revision`) and pinned revisions
(409 `revision_pinned`); unpin first. Delete and prune take the same locks
(drive → file node → file → revision → quota → CAS), so a revision pinned or made non-deletable
after the pruning chose it is skipped. Revision numbers are never reused.

The transaction retries serialization failures/deadlocks up to three attempts,
reusing the staged object, then discards it in `finally`. If SQL fails after physical
promotion, the bytes form an orphan collected by `vaulter:blobs:reconcile --orphans
--prune` after grace. Reconciliation keeps committed winners' bytes.

## Transports

`UploadProtocol` (`Vaulter\Drive\Enums\Uploads\UploadProtocol`) selects the transport per session:

| Protocol      | Value           | Works on | How |
| ------------- | --------------- | -------- | --- |
| **tus**       | `tus`           | protocol; completion on a **local** staging disk only | tus.io CORE — Creation + `HEAD`(`Upload-Offset`) + `PATCH`(`application/offset+octet-stream`). Each chunk is appended to a staged temp; `received_bytes` is the authoritative offset. |
| **S3 multipart** | `s3-multipart` | protocol; completion on a **local** staging disk only | Numbered parts recorded in `v_upload_parts` (re-upload of a part replaces it), stitched on complete. |
| **Direct**    | `direct`        | any disk | A one-shot non-resumable `POST` — the small-file fast path. |

The default is **tus** (the disk-agnostic resumable path). The transport is chosen at session
create time; the `UploadSessionManager` resolves the matching handler (`TusUploadHandler` /
`S3MultipartHandler`) from the container, so a host can swap either implementation.

## Session lifecycle

A resumable upload is a first-class row (`UploadSession`, `v_upload_sessions`). `UploadStatus`
drives the state machine:

```
Initiated → Uploading → Assembled → Committed
     └──────────┴──────────┘
            ↓ (abort / sweep)
       Aborted / Expired
```

| State       | Meaning |
| ----------- | ------- |
| `initiated` | The row exists; no bytes received yet (`received_bytes = 0`). |
| `uploading` | At least one tus `PATCH` / S3 part has landed; `received_bytes` is the authoritative server-side offset. |
| `assembled` | All bytes are present on the staged temp; ready to commit. |
| `committed` | The assembled bytes went through `BlobStore.commitStaged`; the `File` + `FileRevision` + node were created and `assembled_blob_id` is set. **Terminal.** |
| `aborted`   | The client (or a `DELETE`) abandoned the session; staged temp + S3 multipart cleaned. **Terminal.** |
| `expired`   | The sweeper reclaimed a stale session past its TTL. **Terminal.** |

Only `initiated` and `uploading` are **open** (accept bytes, sweepable). Transitions are
guarded by `UploadStatus::canTransitionTo` — a terminal state is never re-opened.

::: info Resumable uploads
The routes are controlled by `vaulter-drive.uploads.resumable.enabled`
(default `true`). Upload tables exist independently of this flag.
:::

## Targets

A session can target one of two shapes (it mirrors `IngestFileInput`):

- **New file** → set `parent_id` (or omit it for the storage root) and a `name`; the commit
  creates a `kind=file` node under that folder.
- **New version** → set `node_id` (the existing `kind=file` node); the commit bumps
  `version_no` and repoints the head.

Authorization is per-target, enforced through the `AccessResolver` at every byte/state move
(both at create and on each subsequent op):

- **New file** → `canCreateIn($actor, $scope)` **and** `Ability::Create` on the
  destination folder.
- **New version** → `Ability::Edit` on the target node.

A denial is a `403` via `PermissionDeniedException`.

## Routes

All routes are mounted under `/{routes.prefix}/v1` (default `api/vaulter/v1`), named
`vaulter.v1.uploads.*`, throttled by `throttle:vaulter-uploads` and always registered:
with `vaulter-drive.uploads.resumable.enabled = false` the operations refuse every act but
the status poll with `403 profile_restriction`. `{upload}` is the session's `upload_id` — a
plain id the controller looks up and the upload operations authorize again on every act.
Full request and response shapes: [REST reference](/reference/rest-api#resumable-uploads).

| Method   | Route                              | Name                    | Purpose |
| -------- | ---------------------------------- | ----------------------- | ------- |
| `POST`   | `/drives/{drive}/uploads`          | `uploads.store`         | Open a session (`protocol`: `tus` or `s3-multipart`); tus answers `Location` + `upload_id`. |
| `GET`    | `/uploads/{upload}`                | `uploads.show`          | Poll the session status. |
| `HEAD`   | `/uploads/{upload}`                | `uploads.tus.head`      | The authoritative `Upload-Offset` (resume point). |
| `PATCH`  | `/uploads/{upload}`                | `uploads.tus.patch`     | Append a chunk; the last one commits. |
| `DELETE` | `/uploads/{upload}`                | `uploads.tus.destroy`   | Abort (staged temp + multipart reclaimed). |
| `OPTIONS`| `/uploads/{upload}`                | `uploads.tus.options`   | tus discovery. |
| `POST`   | `/uploads/{upload}/parts`          | `uploads.parts.store`   | Store a numbered part. |
| `POST`   | `/uploads/{upload}/complete`       | `uploads.complete`      | Complete-multipart → assemble → commit. |

The one-shot path is `POST /drives/{drive}/files` (a multipart form `file` + optional
`parent_id` / `name`); a new revision of an existing file in one request is
`POST /files/{node}/revisions` with `If-Match`.

::: warning A new revision needs the file's tag
A session with `node_id` (a new revision of an existing file) is created only with
`If-Match` carrying that file's current `ETag` — `428` without it, `412` when it is stale — so
a client does not start sending bytes onto a file that changed under it. The tag is checked
when the session is created; the commit at the end does not compare it again (a persisted
expectation in the session is planned after 1.0).
:::

## tus core flow

The tus handler implements the three CORE operations directly (no heavy tus framework —
self-contained over Flysystem + the session table). Appending chunks works on any Flysystem disk, but **completion** is supported on a local staging disk only.

::: warning Completion is local-disk only
`tus` and `s3-multipart` are **protocol** names, not storage capabilities. Completing a resumable session needs a bounded staged read (PLAN4-D19), which Flysystem gives only the local adapter: when the staging disk is remote (S3, object stores) or a custom adapter, `complete` is refused with `upload_conflict` (409) before any storage I/O, nothing is published and the session stays assembled. Single-shot `direct` upload and the content-addressed store on S3 are unaffected. Before upgrading, stage resumable uploads on a local disk and see the completion budget in [compatibility](/reference/compatibility).
:::

1. **Create** — `POST /drives/{drive}/uploads`. The manager mints the session +
   `upload_id` (the tus `Location`) and stamps the TTL. The handler's `initialise()` is a no-op
   (the staged temp is created lazily on the first `PATCH`). Response `201` with
   `Tus-Resumable`, `Location`, `Upload-Offset: 0`.
2. **HEAD** — `head()` returns the **authoritative server-side `received_bytes`** as
   `Upload-Offset`, reconciled against the staged temp's real length (a crashed `PATCH` never
   reports an offset past the bytes that actually landed). A resuming client reads **this**,
   never its own guess.
3. **PATCH** — `patch()` appends the `application/offset+octet-stream` chunk at the declared
   `Upload-Offset`. The offset **must** match the server's current offset (a tus `409 Conflict`
   otherwise — the server, not the client, decides where the bytes go). When `received_bytes`
   reaches `total_size_bytes`, the controller drives `markAssembled` → `CompleteUploadAction` and
   returns the created node (`201`).

::: code-group

<!-- snippet: shell -->

```bash [curl]
# 1. Create the session (1 GiB file, into the storage root).
curl -i -X POST https://app.test/api/vaulter/v1/drives/01J.../uploads \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{"protocol":"tus","total_size_bytes":1073741824,"name":"video.mp4"}'
# → 201, Location: .../v1/uploads/<upload_id>, Upload-Offset: 0

# 2. Ask the server where to resume from.
curl -I https://app.test/api/vaulter/v1/uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' -H 'Authorization: Bearer <user-token>'
# → Upload-Offset: <server offset>

# 3. Send a chunk at that offset.
curl -X PATCH https://app.test/api/vaulter/v1/uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' \
  -H 'Content-Type: application/offset+octet-stream' \
  -H 'Upload-Offset: 0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @chunk-0.bin
# → 204 Upload-Offset: <new>  (more chunks)  |  201 + node JSON (complete)
```

<!-- snippet: host -->

```vue [Vue]
<script setup lang="ts">
import { useUpload } from '@vaulter/vue'

const { uploadResumable, progress, sent, total, abort } = useUpload(driveId)

async function onPick(file: File) {
  // Drives the tus session chunk by chunk; progress is reactive.
  const node = await uploadResumable(file, {
    parentId,                       // or omit for the storage root
    onProgress: (s, t) => console.log(`${s}/${t}`),
  })
  console.log('committed node', node.id)
}
</script>

<template>
  <progress :value="progress" max="1" />
  <span>{{ sent }} / {{ total }}</span>
  <button @click="abort">Cancel</button>
</template>
```

:::

::: tip tus metadata
True tus clients can send an `Upload-Metadata` header (a comma-separated list of
`key base64(value)` pairs). The controller decodes it and merges it over the JSON-body
`metadata`, so both real tus clients and the JSON API are supported.
:::

## S3-multipart flow

When the session's disk is an object store, the bytes upload as **numbered parts**. The
handler records each part (`part_no` / `byte_offset` / `size` / `etag`) in `v_upload_parts` —
a re-upload of a part **replaces** its row (the S3 semantics, encoded in the
`(session, part_no)` composite primary key) — and on `complete` stitches the parts (ascending
by `part_no`) into the assembled staged temp.

<!-- snippet: shell -->

```bash
# Record part 1 (the body is the part bytes).
curl -X POST 'https://app.test/api/vaulter/v1/uploads/<upload_id>/parts?part_no=1&byte_offset=0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @part-1.bin
# → 201 { "part_no": 1, "size": ..., "etag": "\"...\"" }

# Complete → stitch → assemble → commit → returns the created node.
curl -X POST https://app.test/api/vaulter/v1/uploads/<upload_id>/complete \
  -H 'Authorization: Bearer <user-token>'
```

::: info ETag is never the address
The per-part `etag` we store is the object-store handle only (for debug / the real
complete-multipart call) — it is **never** the content address. On `complete` the **stitched
bytes**, not the multipart ETag, are what `ContentStore.stage` hashes (H.3). The default
handler proxies parts through the `StorageDriver`, so the record/part flow runs against a faked/local
disk in tests; completion on a remote S3 disk is refused (see the warning above); a host wanting true presigned-PUT parts swaps
the handler — the record/complete contract is unchanged.
:::

## Assemble → CAS commit & dedup

`CompleteUploadAction` is the **one place** staged bytes cross into the Drive, and it does so
through the same race-free path a one-shot upload uses:

```
staged temp → IngestFileInput → IngestFileAction → ContentStore.stage → quota → BlobStore.commitStaged
```

The whole thing runs in one outer transaction so a session is never half-committed:

1. The staged temp is read back as a stream (it lives on the session's disk under a `tmp/`
   prefix keyed by `upload_id`, so a multi-GB upload survives across requests).
2. `IngestFileAction` stages it locally, **recomputes the `sha256`**, and calls
   `BlobStore.commitStaged` — dedup, the race-free state machine, ref-accounting and quota all apply.
   Two identical files commit to the **same blob** (content-addressed dedup); the session's
   `assembled_blob_id` is set to the blob the bytes deduped into — read from the committed
   version row, never from the client claim.
3. On success the session is marked `Committed`, and the staged temp is **discarded** (commit-
   or-abort cleanup) — done *outside* the transaction so a disk-delete hiccup never rolls back
   a committed file.

### Transaction length of `complete()`

A one-shot `files()->upload()` reads and hashes its bytes *before* any transaction opens. A
resumable `complete()` cannot: it holds the session row lock for the whole outermost transaction,
because that lock is what stops the sweeper and `abort()` from discarding the staged bytes
mid-read. The single pass over those bytes (read, `sha256`, inspection) therefore runs inside
the transaction, with no query in flight — the connection sits `idle in transaction`.

Measured on PostgreSQL 16 with a local staging disk, a 100 MiB file keeps the transaction open
for about 1.1–1.2 s, roughly 0.8 s of it hashing. The time grows linearly with the file (about
12 s per GiB, so the default 5 GiB cap is close to a minute) and with slower staging storage.

Plan for it:

- Keep `idle_in_transaction_session_timeout` (and any proxy idle limit) above
  `max_file_size ÷ staging throughput`; a shorter timeout rolls the completion back (the session
  stays assembled with its bytes intact), but every retry then fails the same way.
- Every concurrent `complete()` holds one database connection for that long; size the pool for
  your expected number of simultaneous large completions, or lower `max_file_size`.
- The session lock is intentional: two completions of one session commit once, and sweep or
  abort lose to a running completion. Shortening the transaction means replacing that lock with a
  persisted claim on the session, which changes the lease contract and is tracked as a separate
  amendment.

A premature `complete()` cannot skip ahead: `markAssembled` is a no-op unless the handler
reports every byte received.

## Abort & sweep

Two paths reclaim a session — both release the protocol-side state (S3
`AbortMultipartUpload` / per-part objects) and discard the staged temp:

- **Abort** (`DELETE /v1/uploads/{upload}`) → terminal state `Aborted` (the client
  cancelled). Idempotent — a terminal session is returned untouched.
- **Sweep** (`php artisan vaulter:uploads:sweep`) → terminal state `Expired`. The command scans
  the **open** sessions (`initiated` | `uploading`) whose `expires_at` has elapsed and reclaims
  each. Schedulable.

<!-- snippet: shell -->

```bash
# Reclaim stale sessions past their TTL (staged temp + S3 multipart cleaned).
php artisan vaulter:uploads:sweep
```

<!-- snippet: host -->

```php
// Schedule it (e.g. app/Console/Kernel.php or routes/console.php):
Schedule::command('vaulter:uploads:sweep')->hourly();
```

::: tip Pair with an S3 lifecycle rule
The sweeper aborts the in-flight multiparts it **knows about** (its `v_upload_parts` rows).
Pair it with an S3 bucket lifecycle rule `AbortIncompleteMultipartUpload` as the backstop for
any orphaned multipart the table never saw — the lifecycle rule is *never* a blob-deletion
mechanism (the CAS bucket itself stays unversioned, H.3).
:::

## Configuration

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'uploads' => [
    'max_file_size' => 5_368_709_120, // bytes; 0 = unlimited
    'allowed_mime_types' => [],
    'resumable' => [
        'enabled' => true,
        'chunk_size' => 8_388_608,
        'max_chunk_size' => 67_108_864,
        'session_ttl_hours' => 24,
        'max_active_sessions_per_actor' => 20,
        'metadata' => [
            'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
            'max_value_length' => 512,
        ],
    ],
],
'trash' => ['retention_days' => 30],
'models' => [
    'upload_session' => \Vaulter\Drive\Models\Uploads\UploadSession::class,
    'upload_part' => \Vaulter\Drive\Models\Uploads\UploadPart::class,
],
```

`max_file_size` is the shared byte limit for ordinary and resumable uploads.
Models are configured under `vaulter-drive.models.*`; resumable routes are controlled
by `vaulter-drive.uploads.resumable.enabled`.

## Testing your extension

The testing kit ships inside the packages (`Vaulter\Testing`, `Vaulter\Drive\Testing`); nothing
in production code imports it.

**Create data through Operations.** `Drive::factory()->for($owner)`, `Node::factory()->file('bytes')->in($drive)`
and `FileRevision::factory()->for($file)->bytes('v2')` provision through the same Actions a request
uses, as a system actor, so quota, sequences, blobs and scan work hold. `forTenant()` picks the tenant.
`InteractsWithVaulter` adds `vaulterFakeStorage()`, `vaulterActingAs($user)`,
`assertVaulterEventRecorded()` / `assertVaulterEventNotRecorded()` and the visibility assertions.

**Content scanner.** `ContentScannerContractTests::assertContentScannerContract($scanner, $samples)` takes
bytes that make your scanner produce each outcome (`clean` is required; `infected`, `unsupported`,
`too_large`, `password_protected`, `access_denied` and `timeout` are optional) and checks that each maps to
the evidence the lifecycle records: a verdict, or a failure with that reason, retryable only for a
timeout. It also checks that bytes the scanner cannot read are never reported clean.

**Scan lifecycle on your configuration.** `ScanLifecycleContractTests` drives a drive's Operations as
your actor, `vaulter:scan:reconcile` and a scripted `FakeContentScanner`:

<!-- snippet: host -->

```php
use Vaulter\Drive\Testing\Contracts\ScanLifecycleContractTests;

it('keeps the scan lifecycle', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(
        static function (): void {
            config()->set('vaulter-drive.scan.mode', 'all_content');
            config()->set('vaulter-drive.scan.scanner_key', 'acme');
            config()->set('vaulter-drive.scan.scanner_version', '1');
            config()->set('queue.default', 'redis');
        },
        $user,
    );
});
```

It proves that scan work is created in the drive and tenant scope of the revision and evidence is never
reused across scopes; that new content is unreadable until it is clean and legacy content is blocked only
under `all_content`; that a lost publication and an expired lease are recovered by the command and a stale
attempt cannot settle; and that switching webhooks off leaves scanning on. The host needs an asynchronous
queue connection and an actor that may create and read in a drive.

**File handlers.** `FileHandlerContractTests::assertFileHandlerContract($handler)` checks the key, the claims,
and that `capabilities()` depends only on the mime type and extension, which the registry caches by.

## See also

- [Blobs](/concepts/storage-and-blobs) — the content-addressed store every upload commits into.
- [Files & folders](/concepts/nodes-and-revisions) — the node tree the committed file lands in.
- [Sharing](/guides/sharing) — publish a committed file via a bearer link.
- [Custom storage driver](/guides/recipes) — swap the disk / object store behind uploads.
- [Frontend composables](/guides/vue) — `useUpload` (direct + resumable).
