<?php

declare(strict_types=1);

namespace Vaulter\Media\Contracts\Cas;

use Vaulter\Attributes\Api;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The high-level content-addressed blob lifecycle: the race-free CAS.
 *
 * commit() prepares bytes; commitStaged() accepts already prepared bytes →
 * find-or-create the blob by its per-scope dedup key inside ONE locked
 * transaction, resurrecting a tombstoned match and promoting bytes only when the
 * row is newly created (a dedup hit skips the physical write). There is NO
 * synchronous ref_count++ — the liveness truth is the pins of the blob
 * ({@see BlobPins}, D23), and ref_count is a reconciled cache of them. An owner
 * pins the committed blob in the same transaction that records its reference.
 */
#[Api]
interface BlobStore
{
    /**
     * Stage the incoming stream, recompute its sha256, and find-or-create the
     * blob for the resolved dedup scope, returning a snapshot of the committed
     * blob (its bytes durable at the canonical key; the id is the committed or
     * deduplicated row's, never derived from the digest). Concurrency-safe: concurrent identical
     * uploads converge on one row via the UNIQUE (scope_key, sha256) backbone (PG
     * advisory lock first; MySQL SELECT…FOR UPDATE; SQLite single-writer).
     *
     * @param resource $stream
     */
    public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): BlobSnapshot;

    /** Commit prepared bytes without discarding them; the staging caller owns cleanup. */
    public function commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): BlobSnapshot;

    /**
     * A read stream over a committed blob's canonical bytes, or null when absent. The current row is read by the
     * id of $blob (a snapshot or its key); the snapshot's own location is never trusted.
     *
     * @return resource|null
     */
    public function read(BlobSnapshot|int|string $blob): mixed;
}
