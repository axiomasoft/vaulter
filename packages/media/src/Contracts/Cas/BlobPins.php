<?php

declare(strict_types=1);

namespace Vaulter\Media\Contracts\Cas;

use Closure;
use Packman\Attributes\Api;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;

/**
 * The pins of a blob: the liveness truth the garbage collector reads. An owner — a module that keeps
 * bytes alive, such as the drive's revision links — pins a blob under an opaque `owner_type` (`<module>.<what>`)
 * and a `reference_key` derived from its own row, in the SAME transaction that writes that row, and unpins it in
 * the transaction that deletes the row. Media never reads an owner's table; ref_count is only a cache of the pins.
 *
 * Every mutation runs inside the caller's {@see VaulterDatabase} transaction (a call outside one
 * throws a LogicException). A pin takes the per-(scope, sha256) lock and the row lock of the blob, so it is ordered
 * against the sweep that would delete the bytes; the caller's own locks come first (lock order: owner → blob).
 */
#[Api]
interface BlobPins
{
    /**
     * Pin $blob (a snapshot or its key) for the owner reference; a repeated pin of the same reference is a no-op.
     * The current row is locked and read by id, whatever the snapshot says: a committed blob is pinned; a
     * tombstoned one only while its bytes still exist (it is then committed again); a deleting or deleted one never.
     *
     * @throws BlobUnavailableException when the blob's bytes are gone: commit them again (BlobStore::commitStaged
     *                                  promotes the bytes and resurrects the row) and pin the returned blob
     */
    public function pin(BlobSnapshot|int|string $blob, string $ownerType, string $referenceKey): void;

    /** Remove the owner reference from $blob (a snapshot or its key); a missing pin is a no-op. */
    public function unpin(BlobSnapshot|int|string $blob, string $ownerType, string $referenceKey): void;

    /**
     * Release the pins of an owner type — only for the owner's own purge or rollback, never for another
     * module's pins. $keys narrows the release to the reference keys it yields; null releases them all.
     *
     * @param  (Closure(): iterable<string>)|null $keys
     * @return int                                the number of pins removed
     */
    public function releaseOwner(string $ownerType, ?Closure $keys = null): int;

    /**
     * The number of pins per owner type, for diagnostics.
     *
     * @return array<string, int>
     */
    public function countByOwner(): array;
}
