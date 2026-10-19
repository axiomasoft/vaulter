---
title: Blob integrity and garbage collection
description: How Vaulter decides a blob may be deleted, and how vaulter:blobs:scrub verifies stored bytes against their sha256 within a sample and a budget.
outline: deep
---

# Blob integrity and garbage collection

Two scheduled-or-manual jobs of the media package look after stored bytes: the **garbage collector**
(`vaulter:blobs:gc`) removes bytes nobody needs, the **scrub** (`vaulter:blobs:scrub`) checks that the bytes
somebody needs are still the bytes that were written. See [Storage and blobs](/concepts/storage-and-blobs) for
the model.

## When a blob is deleted

A blob is deleted only when all of this holds, and it is checked again inside the sweep transaction:

1. **No pin** exists in `v_blob_references`. Pins are the truth; `ref_count` is a cache.
2. The blob is `tombstoned` and its `delete_after_at` is past (the grace window), or it is `pending` for longer
   than the orphan grace and has no pins.
3. The pins are re-read under the lock of `(scope, sha256)` and the row of the blob, then
   `markDeleting → unlink → markDeleted`.

Consequences:

- Pins of a module that is disabled or removed from Composer are **not** released; they keep the bytes. Only the
  owner's purge or repair, proving the reference is gone, removes a pin.
- The same bytes in two dedup scopes are two rows; collecting one never touches the other.
- The same sha256 with a different size is refused at ingest, never overwritten.
- A pin to a tombstoned blob without bytes, or to a `deleting`/`deleted` one, is refused
  (`BlobUnavailableException`) and healed by a new commit of the bytes.
- A pending blob that gets a pin is committed or the pin is refused; it is never lost.

## The scrub

```bash
php artisan vaulter:blobs:scrub                       # up to 100 blobs
php artisan vaulter:blobs:scrub --limit=500 --max-bytes=5000000000 --max-seconds=600
php artisan vaulter:blobs:scrub --scope=<scope> --dry-run
```

It selects committed, non-chunked blobs by `integrity_checked_at`, never-checked first, then the oldest. Each
blob is read as a stream through the storage driver and compared by size and sha256.

| Option | Meaning |
|---|---|
| `--limit` | blobs per run (default `vaulter-media.scrub.limit`, 100) |
| `--max-bytes` | stop after this many bytes read (`vaulter-media.scrub.max_bytes`, no limit) |
| `--max-seconds` | stop after this time (`vaulter-media.scrub.max_seconds`, no limit) |
| `--scope` | only one dedup scope |
| `--dry-run` | read and report, write nothing |

The run ends at the **first** budget used up. Reading an S3 bucket in full is slow and billed per request, so the
scrub is **not scheduled** by default: pick a sample size and a cadence that fits your store (for example a
nightly `--limit` and `--max-seconds`), and the oldest-first order makes successive runs cover everything.

A blob that passes gets a new `integrity_checked_at`. A blob that is missing, has another size or another digest
gets `integrity_failed_at`. The scrub **never** deletes bytes, pins or rows, and never writes the scan tables of
the drive.

| Exit | Meaning |
|---|---|
| 0 | no failure found |
| 1 | at least one failure found |
| 2 | invalid options |

### Seeing and reacting to a failure

- `vaulter:doctor` — `media.blobs.integrity` warns while any blob has `integrity_failed_at`.
- Metrics — `vaulter_blobs_scrub_checked_total`, `vaulter_blobs_scrub_bytes_total`,
  `vaulter_blobs_scrub_failed_total{reason=missing|size|digest}`.
- Downloads — `Blob::isIntact()` is false for a failed blob, and the drive refuses to hand its content out
  (`content_unavailable`, `423`) instead of serving damaged bytes.

To recover, restore the object from a backup or re-upload the same bytes: an ingest of the same sha256 into the
same scope rewrites the object and clears the mark under the blob lock, the same way a re-commit heals a
tombstone. Without that, the mark stays and the doctor keeps warning.

## See also

- [Operation catalogue](/reference/operations) · [Commands](/reference/commands)
- [Trash and purge](/guides/trash)
