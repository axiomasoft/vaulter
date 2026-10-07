---
title: Uploads
description: Resumable uploads — tus.io core on any disk, S3-style multipart on object stores, and a direct one-shot path, all funnelling through the same race-free CAS commit.
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
The transaction locks drive → nodes by ascending id → file → quota → CAS. It reserves a positive
head-size delta with a conditional UPDATE, so simultaneous writers cannot exceed
`quota_bytes`. An unlimited quota is `null`; an exact-boundary reservation succeeds.
The preliminary check is only a fast rejection for new files.

A new revision reads the locked file's current head and counter, allocates
`version_count + 1`, and accounts for the difference from that actual head. Smaller
heads release space; trashed heads keep using space until purge. A subtree copy
reserves all copied head bytes together or rolls back the whole copy.

The transaction retries serialization failures/deadlocks up to three attempts,
reusing the staged object, then discards it in `finally`. If SQL fails after physical
promotion, the bytes form an orphan collected by `vaulter:blobs:reconcile --orphans
--prune` after grace. Reconciliation keeps committed winners' bytes.

## Transports

`UploadProtocol` (`Vaulter\Enums\Uploads\UploadProtocol`) selects the transport per session:

| Protocol      | Value           | Works on | How |
| ------------- | --------------- | -------- | --- |
| **tus**       | `tus`           | **any** Flysystem disk | tus.io CORE — Creation + `HEAD`(`Upload-Offset`) + `PATCH`(`application/offset+octet-stream`). Each chunk is appended to a staged temp; `received_bytes` is the authoritative offset. |
| **S3 multipart** | `s3-multipart` | S3-style disks | Numbered parts recorded in `v_upload_parts` (re-upload of a part replaces it), stitched on complete. |
| **Direct**    | `direct`        | any | A one-shot non-resumable `POST` — the small-file fast path. |

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

- **New file** → `canCreateInWorkspace($actor, $storage)` **and** `Ability::Create` on the
  destination folder.
- **New version** → `Ability::Edit` on the target node.

A denial is a `403` via `PermissionDeniedException`.

## Routes

All routes are mounted under the configured prefix (default `api/vaulter`) and require the
`uploads` feature. `{upload_id}` is the client UNIQUE handle (a plain scalar, **not**
route-model bound — the controller resolves the session and re-authorizes its target).

| Method   | Route                                        | Controller / action          | Purpose |
| -------- | -------------------------------------------- | ---------------------------- | ------- |
| `POST`   | `/storages/{storage}/resumable-uploads`      | `TusProtocolController@create` | Open a session (returns `Location` + `upload_id`). |
| `GET`    | `/resumable-uploads/{upload_id}`             | `TusProtocolController@status` | Poll the session status. |
| `HEAD`   | `/resumable-uploads/{upload_id}`             | `TusProtocolController@head`   | The authoritative `Upload-Offset` (resume point). |
| `PATCH`  | `/resumable-uploads/{upload_id}`             | `TusProtocolController@patch`  | Append a chunk; on completion assemble→commit. |
| `DELETE` | `/resumable-uploads/{upload_id}`             | `TusProtocolController@abort`  | Abort (staged temp + multipart reclaimed). |
| `POST`   | `/resumable-uploads/{upload_id}/parts`       | `S3MultipartProtocolController@parts`| Record (sign/proxy) a numbered part. |
| `POST`   | `/resumable-uploads/{upload_id}/complete`    | `S3MultipartProtocolController@complete` | Complete-multipart → assemble → commit. |

The direct one-shot path is the Drive upload endpoint
`POST /storages/{storage}/uploads` (a multipart form `file` + optional `parent_id` / `node_id`
/ `name`).

## tus core flow

The tus handler implements the three CORE operations directly (no heavy tus framework —
self-contained over Flysystem + the session table) and works on **any disk**.

1. **Create** — `POST /storages/{storage}/resumable-uploads`. The manager mints the session +
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

```bash [curl]
# 1. Create the session (1 GiB file, into the storage root).
curl -i -X POST https://app.test/api/vaulter/storages/01J.../resumable-uploads \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{"protocol":"tus","total_size_bytes":1073741824,"name":"video.mp4"}'
# → 201, Location: .../resumable-uploads/<upload_id>, Upload-Offset: 0

# 2. Ask the server where to resume from.
curl -I https://app.test/api/vaulter/resumable-uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' -H 'Authorization: Bearer <user-token>'
# → Upload-Offset: <server offset>

# 3. Send a chunk at that offset.
curl -X PATCH https://app.test/api/vaulter/resumable-uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' \
  -H 'Content-Type: application/offset+octet-stream' \
  -H 'Upload-Offset: 0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @chunk-0.bin
# → 204 Upload-Offset: <new>  (more chunks)  |  201 + node JSON (complete)
```

```vue [Vue]
<script setup lang="ts">
import { useUpload } from '@vaulter/vue'

const { uploadResumable, progress, sent, total, abort } = useUpload(storageId)

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

```bash
# Record part 1 (the body is the part bytes).
curl -X POST 'https://app.test/api/vaulter/resumable-uploads/<upload_id>/parts?part_no=1&byte_offset=0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @part-1.bin
# → 201 { "part_no": 1, "size": ..., "etag": "\"...\"" }

# Complete → stitch → assemble → commit → returns the created node.
curl -X POST https://app.test/api/vaulter/resumable-uploads/<upload_id>/complete \
  -H 'Authorization: Bearer <user-token>'
```

::: info ETag is never the address
The per-part `etag` we store is the object-store handle only (for debug / the real
complete-multipart call) — it is **never** the content address. On `complete` the **stitched
bytes**, not the multipart ETag, are what `ContentStore.stage` hashes (H.3). The default
handler proxies parts through the `StorageDriver`, so the same flow works against a faked/local
disk in tests and a real S3 disk in production; a host wanting true presigned-PUT parts swaps
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

- **Abort** (`DELETE /resumable-uploads/{upload_id}`) → terminal state `Aborted` (the client
  cancelled). Idempotent — a terminal session is returned untouched.
- **Sweep** (`php artisan vaulter:sweep-uploads`) → terminal state `Expired`. The command scans
  the **open** sessions (`initiated` | `uploading`) whose `expires_at` has elapsed and reclaims
  each. Schedulable.

```bash
# Reclaim stale sessions past their TTL (staged temp + S3 multipart cleaned).
php artisan vaulter:sweep-uploads
```

```php
// Schedule it (e.g. app/Console/Kernel.php or routes/console.php):
Schedule::command('vaulter:sweep-uploads')->hourly();
```

::: tip Pair with an S3 lifecycle rule
The sweeper aborts the in-flight multiparts it **knows about** (its `v_upload_parts` rows).
Pair it with an S3 bucket lifecycle rule `AbortIncompleteMultipartUpload` as the backstop for
any orphaned multipart the table never saw — the lifecycle rule is *never* a blob-deletion
mechanism (the CAS bucket itself stays unversioned, H.3).
:::

## Configuration

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
'names' => ['max_length' => 255],
'trash' => ['retention_days' => 30],
'models' => [
    'upload_session' => \Vaulter\Drive\Models\Uploads\UploadSession::class,
    'upload_part' => \Vaulter\Drive\Models\Uploads\UploadPart::class,
],
```

`max_file_size` is the shared byte limit for ordinary and resumable uploads.
Models are configured under `vaulter-drive.models.*`; resumable routes are controlled
by `vaulter-drive.uploads.resumable.enabled`.

## See also

- [Blobs](/guide/storage-and-blobs) — the content-addressed store every upload commits into.
- [Files & folders](/guide/drive) — the node tree the committed file lands in.
- [Sharing](/guide/sharing) — publish a committed file via a bearer link.
- [Custom storage driver](/guide/recipes) — swap the disk / object store behind uploads.
- [Frontend composables](/guide/vue) — `useUpload` (direct + resumable).
