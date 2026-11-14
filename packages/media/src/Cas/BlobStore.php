<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use RuntimeException;
use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Support\Identity;

/**
 * Stage and hash outside the transaction, then lock (scope_key, sha256) before
 * row lookup, resurrection and promotion. SQL rollback can leave orphan bytes;
 * the reconciler uses this same lock before collecting them. Keeping a blob alive
 * is the owner's pin (BlobPins, D23), not this store's. A dedup hit whose row
 * records another size for the same digest is refused, never overwritten. The
 * same size with different bytes is rewritten from the staged object.
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
    public function commit(mixed $stream, BlobScope $scope, ?string $disk = null): BlobSnapshot
    {
        $staged = $this->content->stage($stream);

        try {
            return $this->commitStaged($staged, $scope, $disk);
        } finally {
            $staged->discard();
        }
    }

    public function commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): BlobSnapshot
    {
        return BlobSnapshot::fromModel($this->commitStagedRow($staged, $scope, $disk));
    }

    /**
     * commitStaged() for the media and drive internals that go on with the committed row in their own transaction
     * (link it, pin it): the row is the one the snapshot of commitStaged() is taken from.
     */
    #[Internal]
    public function commitStagedRow(StagedObject $staged, BlobScope $scope, ?string $disk = null): Blob
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

            // The scrub marked this blob (D49). Claim the heal before the object is touched, so a commit that read
            // the mark before another heal lifted it does not rewrite the restored object. A failure rolls the mark back.
            $heal = !$result['created'] && $blob->integrity_failed_at !== null && $this->blobs->claimHeal($blob);

            if ($result['created']) {
                // Promotion is idempotent; a rollback leaves bytes for reconciliation.
                $this->content->promote(staged: $staged, key: $blob->storage_key, disk: $blob->disk);
            } else {
                $present = $this->content->exists($blob->storage_key, $blob->disk);
                // The row size already matched. Read the object anyway: the same length with other bytes would stay
                // committed under this sha256. A byte-for-byte match is a normal commit and is not rewritten.
                $mismatched = $present && !$this->holds($blob, $staged);

                if ($mismatched) {
                    $this->replace($blob, $staged);
                } elseif ($heal || !$present) {
                    $this->content->promote(staged: $staged, key: $blob->storage_key, disk: $blob->disk);
                }

                if ($heal || $mismatched) {
                    // The mark is lifted only for an object read back with the ingested size and digest. A swap that
                    // did not land leaves the previous object, and this commit is not a success.
                    if (!$this->holds($blob, $staged)) {
                        $reason = $heal
                            ? 'was not healed: the object read back after the rewrite is missing or does not match the ingested bytes (the bad object could not be removed or the write failed), the integrity mark stays.'
                            : 'was not rewritten: the object read back after the rewrite is missing or does not match the staged bytes, so the commit is refused.';

                        throw new RuntimeException('[Vaulter] Blob ['.Identity::stringify($blob->getKey()).'] '.$reason);
                    }

                    if ($heal) {
                        $this->blobs->clearIntegrity($blob);
                    }
                }
            }

            return $blob;
        });
    }

    /**
     * @return resource|null
     */
    public function read(BlobSnapshot|int|string $blob): mixed
    {
        $model = $this->mediaConfig->blobModel();
        $row = $model::query()->whereKey($blob instanceof BlobSnapshot ? $blob->id : $blob)->first();

        return $row instanceof Blob ? $this->content->get($row->storage_key, $row->disk) : null;
    }

    /**
     * Replace the canonical object only after the staged bytes have landed on a sibling key.
     * Storage sits outside the database transaction, so the canonical key is removed after that
     * copy checks out, then the staged bytes are put there. A failed swap keeps the previous
     * object or writes it back; the sibling is removed either way.
     */
    private function replace(Blob $blob, StagedObject $staged): void
    {
        $canonical = $blob->storage_key;
        $disk = $blob->disk;
        $sibling = $canonical.'.swap-'.bin2hex(random_bytes(8));
        $backup = null;

        try {
            $this->content->promote(staged: $staged, key: $sibling, disk: $disk);

            if (!$this->matches(key: $sibling, disk: $disk, staged: $staged)) {
                throw new RuntimeException(
                    '[Vaulter] Blob ['.Identity::stringify($blob->getKey()).'] was not rewritten: the staged bytes did not land on the sibling key, so the canonical object was left untouched.',
                );
            }

            $backup = $this->capture(key: $canonical, disk: $disk);

            try {
                $this->content->delete($canonical, $disk);
                $this->content->promote(staged: $staged, key: $canonical, disk: $disk);
            } catch (Throwable $exception) {
                try {
                    $this->restore(key: $canonical, disk: $disk, backup: $backup);
                } catch (Throwable $restoreFailure) {
                    throw new RuntimeException($exception->getMessage(), previous: $restoreFailure);
                }

                throw $exception;
            }

            if (!$this->holds($blob, $staged)) {
                $this->restore(key: $canonical, disk: $disk, backup: $backup);
            }
        } finally {
            $this->deleteQuietly(key: $sibling, disk: $disk);
            $backup?->discard();
        }
    }

    /** A staged copy of the current object, or null when it is already absent. */
    private function capture(string $key, ?string $disk): ?StagedObject
    {
        $stream = $this->content->get($key, $disk);

        if (!is_resource($stream)) {
            return null;
        }

        try {
            return $this->content->stage($stream);
        } finally {
            fclose($stream);
        }
    }

    /** Put the captured object back when the canonical key no longer holds it. */
    private function restore(string $key, ?string $disk, ?StagedObject $backup): void
    {
        if (!$backup instanceof StagedObject) {
            return;
        }

        if ($this->matches(key: $key, disk: $disk, staged: $backup)) {
            return;
        }

        if ($this->content->exists($key, $disk)) {
            $this->content->delete($key, $disk);
        }

        if ($this->content->exists($key, $disk)) {
            return;
        }

        $this->content->promote(staged: $backup, key: $key, disk: $disk);
    }

    /** A sibling left behind must not replace the rewrite's own failure. */
    private function deleteQuietly(string $key, ?string $disk): void
    {
        try {
            $this->content->delete($key, $disk);
        } catch (Throwable) {
            // The reconciler ignores a non-canonical sibling. Hiding this failure would drop the rewrite error.
        }
    }

    /** Whether the object of $blob, streamed back, has the size and sha256 of the staged bytes. */
    private function holds(Blob $blob, StagedObject $staged): bool
    {
        return $this->matches(key: $blob->storage_key, disk: $blob->disk, staged: $staged);
    }

    /** Whether the object at $key, streamed back, has the size and sha256 of the staged bytes. */
    private function matches(string $key, ?string $disk, StagedObject $staged): bool
    {
        $stream = $this->content->get($key, $disk);

        if (!is_resource($stream)) {
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
