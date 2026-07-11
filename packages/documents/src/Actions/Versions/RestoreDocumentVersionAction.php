<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Versions;

use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Events\Versions\DocumentVersionCreated;
use Vaulter\Documents\Exceptions\NodeDocumentVersionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

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
final readonly class RestoreDocumentVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
    ) {}

    public function execute(Node $node, int|string $versionId, int|string|null $actorId = null): NodeDocumentVersion
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $source = $this->read->forNode($node, $versionId);

        if (!$source instanceof NodeDocumentVersion) {
            throw NodeDocumentVersionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return DB::transaction(function () use ($node, $source, $actorId): NodeDocumentVersion {
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
                max: Config::maxDocumentVersions(),
                headId: $version->getKey(),
            );

            event(new DocumentVersionCreated(
                node: $node,
                version: $version,
                restored: true,
                actorId: $actorId,
            ));

            return $version;
        });
    }
}
