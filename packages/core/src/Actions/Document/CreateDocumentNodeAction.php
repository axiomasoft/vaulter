<?php

declare(strict_types=1);

namespace Vaulter\Actions\Document;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateDocumentNodeData;
use Vaulter\Documents\Handlers\TipTapHandler;
use Vaulter\Enums\NodeKind;
use Vaulter\Events\DocumentNodeCreated;
use Vaulter\Exceptions\NodeNameConflictException;
use Vaulter\Models\Node;
use Vaulter\Models\NodeDocumentVersion;
use Vaulter\Repositories\NodeDocumentVersionStoreRepository;
use Vaulter\Repositories\NodeReadRepository;
use Vaulter\Repositories\NodeStoreRepository;
use Vaulter\Support\DocumentContentHasher;

/**
 * Create a kind=document node under a folder (or the storage root) with its initial
 * v1 document version from TipTap content (§2.5 / M4a). One outer transaction: the
 * node row, the v1 version and the head repoint commit atomically, then a single
 * after-commit event fires.
 *
 * The node mirrors the file-node shape so the unified tree + Drive Actions (move/
 * rename/trash) apply unchanged — quota and the sibling-unique guard come for free.
 * The node carries the Vaulter document mime so the DocumentHandlerRegistry always
 * routes it to the TipTap editor regardless of a title extension.
 *
 * Authorization is enforced UPSTREAM at the controller (canCreate on the
 * destination) — the Action stays pure and only does the row work.
 */
final readonly class CreateDocumentNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private NodeDocumentVersionStoreRepository $versions,
    ) {}

    public function execute(CreateDocumentNodeData $data): Node
    {
        $parentId = $data->parent?->id;

        if ($this->nodes->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $data->name)) {
            throw NodeNameConflictException::forName(name: $data->name, parentId: $parentId);
        }

        return DB::transaction(function () use ($data, $parentId): Node {
            $node = $this->store->create([
                'storage_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Document,
                'name' => $data->name,
                'mime_type' => TipTapHandler::DOCUMENT_MIME,
                'size_bytes' => $data->content->sizeInKb() > 0 ? (int) ($data->content->sizeInKb() * 1024) : 0,
                'owner_id' => $data->actorId,
                'created_by' => $data->actorId,
            ]);

            $version = $this->commitInitialVersion(node: $node, data: $data);

            // Repoint the head to v1 — reusing the file-node head pointer so the
            // unified tree carries the document head the same way it carries a file.
            $this->store->update($node, ['current_version_id' => $version->getKey()]);

            $node = $node->refresh();

            event(new DocumentNodeCreated(node: $node, version: $version, actorId: $data->actorId));

            return $node;
        });
    }

    private function commitInitialVersion(Node $node, CreateDocumentNodeData $data): NodeDocumentVersion
    {
        return $this->versions->create([
            'node_id' => $node->getKey(),
            'version_no' => 1,
            'content' => $data->content->toArray(),
            'format' => $data->format,
            'content_hash' => DocumentContentHasher::hash($data->content),
            'is_autosave' => false,
            'status' => 'committed',
            'created_by' => $data->actorId,
            'created_at' => now(),
        ]);
    }
}
