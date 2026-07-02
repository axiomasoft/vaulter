<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\DocDrive\Models\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Read-side lookups over the append-only v_node_document_versions (§2.5 / M4a). The
 * head version a document node points at, the latest autosave version (for the
 * debounce window), the named-version listing and the full history — all scoped to
 * the node's own id so a version from another node never resolves.
 *
 * Opens no transactions, makes no access decisions — the Actions wrap writes and the
 * controller authorizes via the AccessResolver.
 */
final class NodeDocumentVersionReadRepository
{
    /**
     * The current head a document node points at (its current_version_id), or null
     * when the node has no committed document version yet.
     */
    public function head(Node $node): ?NodeDocumentVersion
    {
        $headId = $node->current_version_id;

        if ($headId === null) {
            return null;
        }

        return $this->query()
            ->where('node_id', $node->getKey())
            ->whereKey($headId)
            ->first();
    }

    /**
     * The most recent version of a node (highest version_no) — the row a restore
     * targets a prior version relative to, and the autosave-debounce baseline.
     */
    public function latest(Node $node): ?NodeDocumentVersion
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * A specific version of the node's document by the version's primary key,
     * bounded to the node so a foreign version id never resolves.
     */
    public function forNode(Node $node, int|string $versionId): ?NodeDocumentVersion
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->whereKey($versionId)
            ->first();
    }

    /**
     * Every version of a document node, newest version_no first (the history
     * listing). Returns a Builder so the controller paginates it.
     *
     * @return Builder<NodeDocumentVersion>
     */
    public function history(Node $node): Builder
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('version_no');
    }

    /**
     * @return Builder<NodeDocumentVersion>
     */
    private function query(): Builder
    {
        $model = Config::nodeDocumentVersionModel();

        return $model::query();
    }
}
