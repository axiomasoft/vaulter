<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;

/**
 * Restore a document node's head to a prior version (§2.5 / M4a). Append-only: a
 * restore never mutates the target version — it COPIES its content into a fresh
 * version that becomes the new head (so the history stays an immutable log and a
 * restore is itself a versioned, auditable event). The new version inherits the
 * restored snapshot's content/yjs and labels its provenance.
 *
 * One outer transaction; prune runs inside it; a single after-commit event fires
 * with restored=true. The version-to-restore is resolved through the repository
 * bounded to the node, so a version id from another node never restores here.
 */
final readonly class RestoreDocumentRevisionAction
{
    public function __construct(
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
        private DocumentsConfig $documentsConfig,
    ) {}

    public function execute(Node $node, int|string $versionId, int|string|null $actorId = null): DocumentRevision
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        $source = $this->read->forNode($node, $versionId);

        if (!$source instanceof DocumentRevision) {
            throw DocumentRevisionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return $this->database->transaction(function () use ($node, $source, $actorId): DocumentRevision {
            $version = $this->store->create([
                'node_id' => $node->getKey(),
                'version_no' => $this->store->nextVersionNo($node),
                'content' => $source->content,
                'yjs_state' => $source->yjs_state,
                'yjs_state_size' => $source->yjs_state_size,
                'format' => $source->format,
                'content_hash' => $source->content_hash,
                'snapshot_label' => sprintf('Restored from v%d', $source->version_no),
                'is_autosave' => false,
                'status' => 'committed',
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

            $this->store->prune(
                node: $node,
                max: $this->documentsConfig->revisionLimit(),
                headId: $version->getKey(),
            );

            event(new DocumentRevisionCreated(
                node: $node,
                version: $version,
                restored: true,
                actorId: $actorId,
            ));

            return $version;
        });
    }
}
