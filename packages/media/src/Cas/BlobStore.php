<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

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
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;

/**
 * Stage and hash outside the transaction, then lock (scope_key, sha256) before
 * row lookup, resurrection and promotion. SQL rollback can leave orphan bytes;
 * the reconciler uses this same lock before collecting them.
 */
#[Api]
final readonly class BlobStore implements BlobStoreContract
{
    #[Internal]
    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private FileRevisionBlobStoreRepository $edges,
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

            // Promotion is idempotent; a rollback leaves bytes for reconciliation.
            if ($result['created'] || !$this->content->exists($result['blob']->storage_key, $result['blob']->disk)) {
                $this->content->promote(staged: $staged, key: $result['blob']->storage_key, disk: $result['blob']->disk);
            }

            return $result['blob'];
        });
    }

    /**
     * @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes
     */
    public function attach(FileRevision $version, Blob $blob, array $attributes = []): void
    {
        $this->edges->attach(version: $version, blob: $blob, attributes: $attributes);
    }

    public function detach(FileRevision $version, Blob $blob, string $role = 'data', int $ordinal = 0): void
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
