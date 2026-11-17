<?php

declare(strict_types=1);

namespace Vaulter\Media\Data\Snapshots;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;

/**
 * Read-only view of one committed blob, taken in the transaction that committed it. It describes the blob, it does
 * not grant anything: the store and the pins re-read the current row by `id` under their locks, so a stale snapshot
 * cannot bring back deleted or quarantined bytes. `disk` and `storageKey` are infrastructure facts, never a REST body.
 */
#[Api]
final readonly class BlobSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $scopeKey,
        public string $sha256,
        public int $sizeBytes,
        public string $disk,
        public string $storageKey,
        public BlobStatus $status,
        public bool $intact,
        public bool $chunked,
        public ?string $tenantId,
        public ?string $storageId,
    ) {}

    /** @internal */
    #[Internal]
    public static function fromModel(Blob $blob): self
    {
        return new self(
            id: SnapshotValue::id($blob->getKey()),
            scopeKey: $blob->scope_key,
            sha256: $blob->sha256,
            sizeBytes: $blob->size_bytes,
            disk: $blob->disk,
            storageKey: $blob->storage_key,
            status: $blob->status,
            intact: $blob->isIntact(),
            chunked: (bool) $blob->getAttribute('chunked'),
            tenantId: SnapshotValue::nullableId($blob->tenant_id),
            storageId: SnapshotValue::nullableId($blob->storage_id),
        );
    }
}
