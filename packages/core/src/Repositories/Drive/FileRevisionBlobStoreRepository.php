<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;

/**
 * Write-side row work for the v_file_revision_blobs edge table — the GC liveness
 * truth. A blob is live iff an edge here references it, so attaching /
 * detaching an edge is what actually changes liveness; ref_count is only a
 * reconciled cache. Opens no transactions (the caller wraps it). Idempotent
 * upsert so a re-attach of the same (version, blob, role, ordinal) is a no-op.
 */
final readonly class FileRevisionBlobStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * Create (or re-affirm) a version↔blob edge.
     *
     * @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes
     */
    public function attach(FileRevision $version, Blob $blob, array $attributes = []): void
    {
        $role = $attributes['role'] ?? 'data';
        $ordinal = $attributes['ordinal'] ?? 0;

        $this->query()->updateOrInsert(
            [
                'file_revision_id' => $version->getKey(),
                'blob_id' => $blob->getKey(),
                'role' => $role,
                'ordinal' => $ordinal,
            ],
            [
                'offset_bytes' => $attributes['offset_bytes'] ?? null,
                'length_bytes' => $attributes['length_bytes'] ?? null,
            ],
        );
    }

    /**
     * Remove a version↔blob edge. The blob's bytes are NOT deleted here — it just
     * becomes a GC candidate once reconciliation recomputes ref_count = 0.
     */
    public function detach(FileRevision $version, Blob $blob, string $role = 'data', int $ordinal = 0): void
    {
        $this->query()
            ->where('file_revision_id', $version->getKey())
            ->where('blob_id', $blob->getKey())
            ->where('role', $role)
            ->where('ordinal', $ordinal)
            ->delete();
    }

    /**
     * Whether ANY edge still references this blob — the liveness re-check the GC
     * runs under the lock before it tombstones.
     */
    public function hasLiveEdge(Blob $blob): bool
    {
        return $this->query()->where('blob_id', $blob->getKey())->exists();
    }

    /**
     * Count the edges referencing this blob — the reconciled source of truth for
     * the ref_count cache.
     */
    public function countEdges(Blob $blob): int
    {
        return $this->query()->where('blob_id', $blob->getKey())->count();
    }

    /**
     * @return Builder<FileRevisionBlob>
     */
    private function query(): Builder
    {
        $model = $this->vaulterConfig->model('file_revision_blob');

        /** @var Builder<FileRevisionBlob> $query */
        $query = $model::query();

        return $query;
    }
}
