<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Versions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Versions\CreateNamedVersionData;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Versions\DocumentVersionCreated;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

/**
 * Snapshot a document node's current head content as a NAMED (non-autosave) version
 * with a label (§2.5 / M4a). The named version becomes the new head; keepForever
 * pins it out of the prune window. One outer transaction; prune runs inside it
 * (named keep_forever rows never count against the window); a single after-commit
 * event fires.
 *
 * A named snapshot CONVERTS the working state into a durable checkpoint — distinct
 * from autosave churn (a named version is never overwritten by the debounce path).
 */
final readonly class CreateNamedVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
    ) {}

    public function execute(CreateNamedVersionData $data): NodeDocumentVersion
    {
        $node = $data->node;

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        return $this->database->transaction(function () use ($node, $data): NodeDocumentVersion {
            $head = $this->read->head($node) ?? $this->read->latest($node);

            $version = $this->store->create([
                'node_id' => $node->getKey(),
                'version_no' => $this->store->nextVersionNo($node),
                'content' => $head instanceof NodeDocumentVersion ? $head->content : null,
                'yjs_state' => $head?->yjs_state,
                'yjs_state_size' => $head?->yjs_state_size,
                'format' => $head instanceof NodeDocumentVersion ? $head->format : DocumentType::RichText->value,
                'content_hash' => $head?->content_hash,
                'snapshot_label' => $data->label,
                'is_autosave' => false,
                'keep_forever' => $data->keepForever,
                'status' => 'committed',
                'created_by' => $data->actorId,
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
                restored: false,
                actorId: $data->actorId,
            ));

            return $version;
        });
    }
}
