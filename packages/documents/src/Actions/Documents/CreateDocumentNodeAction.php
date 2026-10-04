<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Events\Documents\DocumentNodeCreated;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Create a kind=document node under a folder (or the storage root) with its initial
 * v1 document version from the given content (§2.5 / M4a). The parent row is locked
 * and must be a live folder of the same storage; no parent means the root folder,
 * never a parentless node. One outer transaction: the
 * node row, the v1 version and the head repoint commit atomically, then a single
 * after-commit event fires.
 *
 * The node mirrors the file-node shape so the unified tree + Drive Actions (move/
 * rename/trash) apply unchanged — quota and the sibling-unique guard come for free.
 * The node carries the mime its resolved handler declares (the request resolves it):
 * the synthetic Vaulter document mime routes to the TipTap editor, while an xlsx mime
 * routes a spreadsheet node to the Univer handler — both via the same registry (§7).
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
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(CreateDocumentNodeData $data): Node
    {
        $name = NodeName::normalize($data->name);
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Content
        // is handler-agnostic (DocumentContent); the limit only applies to the
        // TipTap shape, not an opaque non-rich-text snapshot (e.g. a spreadsheet).
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin(Config::maxDocumentSizeKb());
        }

        return $this->database->transaction(function () use ($data, $name, $parentId): Node {
            $this->database->lockTreeShared($data->storage->id);

            $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
            if (!$this->tree->sameStorage($parent, $data->storage->id)) {
                throw InvalidDestinationException::otherDrive(destination: $parent, storageId: $data->storage->id);
            }

            $this->tree->assertActiveFolder($parent);

            if ($this->nodes->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $name)) {
                throw NodeNameConflictException::forName(name: $name, parentId: $parentId);
            }

            $node = $this->store->create([
                'storage_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Document,
                'name' => $name,
                'mime_type' => $data->mimeType,
                'size_bytes' => $data->content->sizeInBytes(),
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
            'node_id' => $node->id,
            'version_no' => 1,
            'content' => $data->content->toArray(),
            'format' => $data->format,
            'content_hash' => $data->content->contentHash(),
            'is_autosave' => false,
            'status' => 'committed',
            'created_by' => $data->actorId,
            'created_at' => now(),
        ]);
    }
}
