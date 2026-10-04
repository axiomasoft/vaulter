<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Read-side lookups over the immutable v_file_versions (§2.4) for the HTTP layer:
 * resolve the head version a file node points at, or a specific historical
 * version by its key — both scoped to the node's own file so a version id from a
 * different file can never be streamed through another node.
 *
 * M2 will route these through the access-aware queryForUser() seam; this slice
 * runs open.
 */
final class FileRevisionReadRepository
{
    /**
     * The current head version a file node points at (its current_version_id), or
     * null when the node is not a committed file.
     */
    public function head(Node $node): ?FileRevision
    {
        return $node->currentVersion()->first();
    }

    /**
     * A specific version of the node's file by the version's primary key, bounded
     * to the node's own file_id so a foreign version id never resolves. Returns
     * null when the node has no file or the version does not belong to it.
     */
    public function forNode(Node $node, int|string $versionId): ?FileRevision
    {
        $fileId = $node->file_id;

        if ($fileId === null) {
            return null;
        }

        return $this->query()
            ->where('file_id', $fileId)
            ->whereKey($versionId)
            ->first();
    }

    /**
     * Every version of a file, newest version_no first (the history listing).
     *
     * @return Builder<FileRevision>
     */
    public function forFile(File $file): Builder
    {
        return $this->query()
            ->where('file_id', $file->getKey())
            ->orderByDesc('version_no');
    }

    /**
     * @return Builder<FileRevision>
     */
    private function query(): Builder
    {
        $model = Config::fileVersionModel();

        return $model::query();
    }
}
