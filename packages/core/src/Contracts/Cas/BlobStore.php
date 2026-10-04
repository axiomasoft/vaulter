<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * The high-level content-addressed blob lifecycle (H.3): the race-free CAS.
 *
 * commit() is the single entry point — stage → hash the staged file →
 * find-or-create the blob by its per-scope dedup key inside ONE locked
 * transaction, resurrecting a tombstoned match and promoting bytes only when the
 * row is newly created (a dedup hit skips the physical write). There is NO
 * synchronous ref_count++ — liveness truth is the existence of an edge in
 * v_file_version_blobs, and ref_count is a reconciled cache.
 *
 * attach()/detach() manage those version↔blob edges.
 */
interface BlobStore
{
    /**
     * Stage the incoming stream, recompute its sha256, and find-or-create the
     * blob for the resolved dedup scope, returning the committed Blob row (its
     * bytes durable at the canonical key). Concurrency-safe: concurrent identical
     * uploads converge on one row via the UNIQUE (scope, sha256) backbone (PG
     * advisory lock first; MySQL SELECT…FOR UPDATE; SQLite single-writer).
     *
     * @param resource $stream
     */
    public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): Blob;

    /**
     * Create the version↔blob edge in v_file_version_blobs. The edge — not
     * ref_count — is the liveness truth (H.3).
     *
     * @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes
     */
    public function attach(FileVersion $version, Blob $blob, array $attributes = []): void;

    /**
     * Remove a version↔blob edge. A blob with no remaining edges becomes a GC
     * candidate at the next reconciliation; the bytes are NOT deleted here.
     */
    public function detach(FileVersion $version, Blob $blob, string $role = 'data', int $ordinal = 0): void;

    /**
     * A read stream over a committed blob's canonical bytes, or null when absent.
     *
     * @return resource|null
     */
    public function read(Blob $blob): mixed;
}
