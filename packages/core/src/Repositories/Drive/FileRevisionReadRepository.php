<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side lookups over the immutable v_file_revisions (§2.4) for the HTTP layer:
 * resolve the head version a file node points at, or a specific historical
 * version by its key — both scoped to the node's own file so a version id from a
 * different file can never be streamed through another node.
 *
 * M2 will route these through the access-aware queryForUser() seam; this slice
 * runs open.
 */
final class FileRevisionReadRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * The head revision of a file node's aggregate (v_files.current_revision_id),
     * or null when the node is not a committed file.
     */
    public function head(Node $node): ?FileRevision
    {
        if (app(TenancyBoundary::class)->enforced()) {
            app(TenancyBoundary::class)->assertNode($node);
            $node = $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
        }

        return $node->file()->first()?->currentRevision()->first();
    }

    /**
     * A specific version of the node's file by the version's primary key, bounded
     * to the node's own file aggregate so a foreign version id never resolves. Returns
     * null when the node has no file or the version does not belong to it.
     */
    public function forNode(Node $node, int|string $versionId): ?FileRevision
    {
        if (app(TenancyBoundary::class)->enforced()) {
            app(TenancyBoundary::class)->assertNode($node);
            $node = $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
        }
        $file = $node->file()->first();

        if ($file === null) {
            return null;
        }

        return $this->query()
            ->where('file_id', $file->getKey())
            ->whereKey($versionId)
            ->first();
    }

    /**
     * Every version of a file, newest number first (the history listing).
     *
     * @return Builder<FileRevision>
     */
    public function forFile(File $file): Builder
    {
        return $this->query()
            ->where('file_id', $file->getRawOriginal($file->getKeyName()))
            ->orderByDesc('number');
    }

    /**
     * @return Builder<FileRevision>
     */
    private function query(): Builder
    {
        $model = $this->vaulterConfig->model('file_revision');

        return app(TenancyBoundary::class)->constrain($model::query(), 'file.node.storage');
    }
}
