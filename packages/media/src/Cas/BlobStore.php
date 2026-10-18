<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use RuntimeException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Support\Identity;

/**
 * Stage and hash outside the transaction, then lock (scope_key, sha256) before
 * row lookup, resurrection and promotion. SQL rollback can leave orphan bytes;
 * the reconciler uses this same lock before collecting them. Keeping a blob alive
 * is the owner's pin (BlobPins, D23), not this store's. A dedup hit whose row
 * records another size for the same digest is refused, never overwritten.
 */
#[Api]
final readonly class BlobStore implements BlobStoreContract
{
    private const int CHUNK_BYTES = 1_048_576;

    #[Internal]
    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private VaulterDatabase $database,
        private MediaConfig $mediaConfig,
    ) {}

    /**
     * @param resource $stream
     */
    public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): Blob
    {
        $staged = $this->content->stage($stream);

        try {
            return $this->commitStaged($staged, $scope, $disk);
        } finally {
            $staged->discard();
        }
    }

    public function commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): Blob
    {
        $disk ??= $this->mediaConfig->disk();
        $storageKey = $this->content->keyFor(scope: $scope, sha256: $staged->sha256());

        return $this->database->transaction(function () use ($staged, $scope, $disk, $storageKey): Blob {
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

            // The same digest with another size is a collision or a corrupted row (D23): refuse the ingest, never
            // overwrite. The throw rolls back the resurrection findOrCreate() may have written.
            if (!$result['created'] && $result['blob']->size_bytes !== $staged->sizeBytes()) {
                throw new RuntimeException(
                    '[Vaulter] Blob ['.Identity::stringify($result['blob']->getKey()).'] holds sha256 '.$staged->sha256().' with '
                    .$result['blob']->size_bytes.' bytes, the staged content has '.$staged->sizeBytes().': the ingest is refused '
                    .'and the stored object is left untouched. Fix: inspect the blob row and its object — a size that does not match its digest is a corrupted row.',
                );
            }

            $blob = $result['blob'];

            // The scrub marked this blob (D49): its object is missing or wrong. The staged bytes are hashed here and
            // match the row's digest and size, so the object is rewritten — removed first, as the conditional promote
            // would keep the bad one — and the mark is lifted, all under the lock. The heal is claimed with a write
            // before the object is touched, so a commit that read the mark before another heal lifted it does not
            // delete the restored object. A failure rolls the mark back.
            $heal = !$result['created'] && $blob->integrity_failed_at !== null && $this->blobs->claimHeal($blob);
            if ($heal) {
                $this->content->delete($blob->storage_key, $blob->disk);
            }

            // Promotion is idempotent; a rollback leaves bytes for reconciliation.
            if ($result['created'] || $heal || !$this->content->exists($blob->storage_key, $blob->disk)) {
                $this->content->promote(staged: $staged, key: $blob->storage_key, disk: $blob->disk);
            }

            if ($heal) {
                // The mark is lifted only for an object read back with the ingested size and digest: a delete the
                // store did not carry out leaves the bad object, and the conditional promote keeps it.
                if (!$this->holds($blob, $staged)) {
                    throw new RuntimeException('[Vaulter] Blob ['.Identity::stringify($blob->getKey()).'] was not healed: the object read back after the rewrite is missing or does not match the ingested bytes (the bad object could not be removed or the write failed), the integrity mark stays.');
                }

                $this->blobs->clearIntegrity($blob);
            }

            return $blob;
        });
    }

    /**
     * @return resource|null
     */
    public function read(Blob $blob): mixed
    {
        return $this->content->get($blob->storage_key, $blob->disk);
    }

    /** Whether the object of $blob, streamed back, has the size and sha256 of the staged bytes. */
    private function holds(Blob $blob, StagedObject $staged): bool
    {
        $stream = $this->content->get($blob->storage_key, $blob->disk);

        if ($stream === null) {
            return false;
        }

        try {
            $hash = hash_init('sha256');
            $read = 0;

            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    return false;
                }

                hash_update($hash, $chunk);
                $read += strlen($chunk);

                if ($read > $staged->sizeBytes()) {
                    return false;
                }
            }
        } finally {
            fclose($stream);
        }

        return $read === $staged->sizeBytes() && hash_equals(strtolower($staged->sha256()), hash_final($hash));
    }
}
