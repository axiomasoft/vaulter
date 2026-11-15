<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Support\Identity;

/**
 * Row work for the v_file_revision_blobs edge table: which blobs a revision is made
 * of. The edge does not keep a blob alive — its media pin does — so the only
 * caller is the drive's RevisionBlobLinks, which writes the edge and the pin in one
 * transaction. Opens no transactions (the caller wraps it). Idempotent upsert so a
 * re-attach of the same (version, blob, role, ordinal) is a no-op.
 */
#[Internal]
final readonly class FileRevisionBlobStoreRepository
{
    public function __construct(
        private readonly DriveConfig $vaulterConfig,
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
     * Remove a version↔blob edge. The blob's bytes are NOT deleted here, and the
     * pin is the caller's to remove.
     */
    public function detach(FileRevision $version, Blob|int|string $blob, string $role = 'data', int $ordinal = 0): void
    {
        $this->query()
            ->where('file_revision_id', $version->getKey())
            ->where('blob_id', $blob instanceof Blob ? $blob->getKey() : $blob)
            ->where('role', $role)
            ->where('ordinal', $ordinal)
            ->delete();
    }

    /**
     * The edges of the given revisions, ordered by their primary key.
     *
     * @param  list<int|string>                                                                           $revisionIds
     * @return list<array{file_revision_id: int|string, blob_id: int|string, role: string, ordinal: int}>
     */
    public function forRevisions(array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }

        $edges = [];
        foreach ($this->query()->toBase()->whereIn('file_revision_id', $revisionIds)
            ->orderBy('file_revision_id')->orderBy('blob_id')->orderBy('role')->orderBy('ordinal')
            ->get(['file_revision_id', 'blob_id', 'role', 'ordinal']) as $row) {
            $revisionId = Identity::key($row->file_revision_id);
            $blobId = Identity::key($row->blob_id);
            if ($revisionId === null || $blobId === null || !is_string($row->role) || !is_numeric($row->ordinal)) {
                throw new LogicException('[Vaulter] A revision edge row has no usable key.');
            }
            $edges[] = ['file_revision_id' => $revisionId, 'blob_id' => $blobId, 'role' => $row->role, 'ordinal' => (int) $row->ordinal];
        }

        return $edges;
    }

    /**
     * Delete every edge of the given revisions; the number deleted.
     *
     * @param list<int|string> $revisionIds
     */
    public function deleteForRevisions(array $revisionIds): int
    {
        if ($revisionIds === []) {
            return 0;
        }

        return $this->query()->toBase()->whereIn('file_revision_id', $revisionIds)->delete();
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
