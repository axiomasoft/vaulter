# ADR-0006 — Content-addressed blobs & race-free GC

**Status:** accepted

## Context

Binary files need deduplication (the same bytes uploaded twice must not double
storage), immutable history, and cheap rename (renaming a file must not move
bytes). A naive `ref_count` decremented inline is a hot-row on popular blobs and
drifts under concurrency, and a scope-free storage path lets two dedup scopes
share one physical object so one scope's GC deletes the other's live bytes.

## Decision

- **Content-addressed store (CAS) over Flysystem.** A blob is keyed by
  `sha256` of its bytes; its physical key is **scope-qualified**
  `<scope>/ab/cd/<sha256>` so per-scope dedup never aliases across scopes. The
  hash is computed over a **staged local temp file** (seekable), not a live
  stream; on S3 the server verifies via `x-amz-checksum-sha256`.
- **The edge table is the truth, `ref_count` is a cache.** Liveness of a blob is
  the *existence of an edge* in `v_file_version_blobs`; `ref_count` is rebuilt by
  reconciliation, never the sole source of truth. The synchronous `ref_count++`
  is removed from the commit path.
- **Two-phase, lock-first GC with resurrection.** Blob states are
  `PENDING → COMMITTED → TOMBSTONED → DELETED`. Every transaction that changes a
  `(scope, sha256)` liveness decision takes `pg_advisory_xact_lock(hash(scope||sha256))`
  **first** and decides only from rows read *after* the lock (`SELECT … FOR UPDATE`).
  A concurrent upload that finds a `TOMBSTONED` blob **resurrects** it in the same
  locked transaction; the sweeper only deletes `TOMBSTONED AND delete_after_at < now()`
  after re-checking under the lock, with a grace window ≥ max multipart-commit
  latency.

## Consequences

- Cross-scope dedup is safe (a scope's GC can never delete another scope's bytes);
  online GC races resolve into resurrection rather than data loss.
- GC is ACL-blind: a node a user cannot see still pins its blob.
- `ref_count` may lag transiently; reconciliation/alerts catch drift and orphans.
- The CAS bucket is unversioned — S3 lifecycle/versioning is **never** used as a
  blob-deletion mechanism (only `AbortIncompleteMultipartUpload` + `tmp/` expiry).
