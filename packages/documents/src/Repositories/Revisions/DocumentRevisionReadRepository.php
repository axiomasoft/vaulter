<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Revisions;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Support\Identity;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/**
 * Read-side lookups over the immutable checkpoints in v_document_revisions: the head
 * of a node's document, one checkpoint and the history — always scoped to the node's
 * own aggregate, so a revision id of another document (or a file revision with the
 * same id) never resolves.
 *
 * Opens no transactions, makes no access decisions.
 */
#[Internal]
final class DocumentRevisionReadRepository
{
    public function __construct(
        private readonly DocumentsConfig $documentsConfig,
    ) {}

    /**
     * The head checkpoint of the node's document, or null when the node has no
     * document aggregate.
     */
    public function head(Node $node): ?DocumentRevision
    {
        $document = Document::query()->where('node_id', $node->getKey())->first(['id', 'current_revision_id']);

        if ($document === null || $document->current_revision_id === null) {
            return null;
        }

        $model = $this->documentsConfig->model('document_revision');

        return $model::query()
            ->where('document_id', $document->getKey())
            ->whereKey($document->current_revision_id)
            ->first();
    }

    /**
     * A checkpoint of the node's document by its primary key.
     */
    public function forNode(Node $node, int|string $revisionId): ?DocumentRevision
    {
        $model = $this->documentsConfig->model('document_revision');
        if ($revisionId === '' || !Identity::fitsKey(new $model, $revisionId)) {
            return null;
        }

        return $this->forDocumentOf($node)->whereKey($revisionId)->first();
    }

    /**
     * Every checkpoint of the node's document, newest number first. Returns a
     * Builder so the controller paginates it.
     *
     * @return Builder<DocumentRevision>
     */
    public function history(Node $node): Builder
    {
        return $this->forDocumentOf($node)->orderByDesc('number');
    }

    /**
     * @return Builder<DocumentRevision>
     */
    private function forDocumentOf(Node $node): Builder
    {
        $model = $this->documentsConfig->model('document_revision');

        return $model::query()->whereIn(
            'document_id',
            Document::query()->where('node_id', $node->getKey())->select('id'),
        );
    }
}
