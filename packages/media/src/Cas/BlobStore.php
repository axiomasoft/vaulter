<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Illuminate\Support\Facades\DB;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileVersionBlobStoreRepository;
use Vaulter\Support\Config;

/**
 * The race-free content-addressed blob store (H.3). commit() is the single CAS
 * entry point and runs ONE transaction whose FIRST statement is the
 * per-(scope,sha256) advisory lock, so every liveness decision is made from rows
 * read after the lock:
 *
 *   1. stage the incoming stream to a local seekable temp file, recompute sha256
 *      from THOSE bytes (never the live stream / client hash / S3 ETag);
 *   2. open the transaction, take the per-(scope,sha256) lock as statement #1;
 *   3. find-or-create the blob by its per-scope dedup key — a tombstoned match is
 *      RESURRECTED in the same locked transaction (this is what makes online GC
 *      safe), the UNIQUE (scope, sha256) constraint is the portable race backbone
 *      (loser re-SELECTs + reuses);
 *   4. promote bytes to the canonical key ONLY when the row is newly created — a
 *      dedup hit skips the physical write;
 *   5. NO synchronous ref_count++ (hot-row) — liveness truth is the edge in
 *      v_file_version_blobs, ref_count is a reconciled cache.
 */
final readonly class BlobStore implements BlobStoreContract
{
    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private FileVersionBlobStoreRepository $edges,
    ) {}

    /**
     * @param resource $stream
     */
    public function commit(mixed $stream, ?int $tenantId = null, ?int $storageId = null, ?string $disk = null): Blob
    {
        $dedupScope = Config::dedupScope();
        $disk ??= Config::defaultDisk();

        // Stage + hash OUTSIDE the transaction: hashing a multi-GB upload must not
        // hold the row/advisory lock. The lock guards only the liveness decision.
        $staged = $this->content->stage($stream);
        $scopeSegment = $this->scopeSegment(dedupScope: $dedupScope, tenantId: $tenantId, storageId: $storageId);
        $storageKey = $this->content->keyFor(scope: $scopeSegment, sha256: $staged->sha256());

        try {
            return DB::transaction(function () use (
                $staged, $dedupScope, $tenantId, $storageId, $disk, $storageKey
            ): Blob {
                // H.3 invariant: the lock is the FIRST statement; every liveness
                // decision below reads rows acquired after it.
                $this->lock->acquire(scope: $this->lockScope($dedupScope, $tenantId, $storageId), sha256: $staged->sha256());

                $result = $this->blobs->findOrCreate(
                    sha256: $staged->sha256(),
                    sizeBytes: $staged->sizeBytes(),
                    storageKey: $storageKey,
                    disk: $disk,
                    dedupScope: $dedupScope,
                    tenantId: $tenantId,
                    storageId: $storageId,
                );

                // Promote bytes only when the row is newly created (dedup hit ⇒
                // skip the physical write). promote() is itself idempotent and
                // conditional, so a resurrected row whose object may be gone is
                // re-promoted safely.
                if ($result['created'] || !$this->content->exists($result['blob']->storage_key, $result['blob']->disk)) {
                    $this->content->promote(staged: $staged, key: $result['blob']->storage_key, disk: $result['blob']->disk);
                }

                return $result['blob'];
            });
        } finally {
            $staged->discard();
        }
    }

    /**
     * @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes
     */
    public function attach(FileVersion $version, Blob $blob, array $attributes = []): void
    {
        $this->edges->attach(version: $version, blob: $blob, attributes: $attributes);
    }

    public function detach(FileVersion $version, Blob $blob, string $role = 'data', int $ordinal = 0): void
    {
        $this->edges->detach(version: $version, blob: $blob, role: $role, ordinal: $ordinal);
    }

    /**
     * @return resource|null
     */
    public function read(Blob $blob): mixed
    {
        return $this->content->get($blob->storage_key, $blob->disk);
    }

    /**
     * The scope SEGMENT baked into the storage_key (H.3): the dedup-scope value as
     * a path-safe string. global → 'global'; tenant → 't<id>' (or 'tnull' when
     * tenancy is off); storage → 's<id>' (or 'snull'). Distinct scopes therefore
     * never resolve to the same physical object.
     */
    private function scopeSegment(string $dedupScope, ?int $tenantId, ?int $storageId): string
    {
        return match ($dedupScope) {
            'storage' => 's'.($storageId ?? 'null'),
            'tenant' => 't'.($tenantId ?? 'null'),
            default => 'global',
        };
    }

    /**
     * The lock-namespace key for the advisory lock — the same scope discriminator
     * the dedup-UNIQUE index keys on, so the lock and the constraint guard the
     * same liveness unit.
     */
    private function lockScope(string $dedupScope, ?int $tenantId, ?int $storageId): string
    {
        return $this->scopeSegment(dedupScope: $dedupScope, tenantId: $tenantId, storageId: $storageId);
    }
}
