<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileVersionBlobStoreRepository;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * Stage and hash outside the transaction, then lock (scope_key, sha256) before
 * row lookup, resurrection and promotion. SQL rollback can leave orphan bytes;
 * the reconciler uses this same lock before collecting them.
 */
final readonly class BlobStore implements BlobStoreContract
{
    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private FileVersionBlobStoreRepository $edges,
        private VaulterDatabase $database,
    ) {}

    /**
     * @param resource $stream
     */
    public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): Blob
    {
        $disk ??= Config::defaultDisk();

        // Stage + hash OUTSIDE the transaction: hashing a multi-GB upload must not
        // hold the row/advisory lock. The lock guards only the liveness decision.
        $staged = $this->content->stage($stream);
        $storageKey = $this->content->keyFor(scope: $scope, sha256: $staged->sha256());

        try {
            return $this->database->transaction(function () use (
                $staged, $scope, $disk, $storageKey
            ): Blob {
                // H.3 invariant: the lock is the FIRST statement; every liveness
                // decision below reads rows acquired after it.
                $this->lock->acquire(scope: $scope->key(), sha256: $staged->sha256());

                $result = $this->blobs->findOrCreate(
                    sha256: $staged->sha256(),
                    sizeBytes: $staged->sizeBytes(),
                    storageKey: $storageKey,
                    disk: $disk,
                    scope: $scope,
                    tenantId: $scope->tenantId(),
                    storageId: $scope->storageId(),
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
}
