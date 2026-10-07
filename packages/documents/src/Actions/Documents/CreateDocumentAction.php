<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use LogicException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Documents\Support\DocumentTypeKey;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Create a kind=document node under a folder (or the storage root) with its document
 * aggregate and first checkpoint from the given content (§2.5 / M4a). The parent row
 * is locked and must be a live folder of the same storage; no parent means the root
 * folder, never a parentless node. One outer transaction: the node row, the
 * aggregate (working state at state version 1) and checkpoint #1 commit atomically,
 * then a single after-commit event fires.
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
final readonly class CreateDocumentAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private DocumentStoreRepository $documents,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DocumentsConfig $documentsConfig,
    ) {}

    public function execute(CreateDocumentInput $data): Node
    {
        $name = NodeName::normalize($data->name);
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Content
        // is handler-agnostic (DocumentContent); the limit only applies to the
        // TipTap shape, not an opaque non-rich-text snapshot (e.g. a spreadsheet).
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin($this->documentsConfig->maxContentSizeKb());
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
                'drive_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Document,
                'name' => $name,
                'mime_type' => $data->mimeType,
                'size_bytes' => $data->content->sizeInBytes(),
                'owner_id' => $data->actorId,
                'created_by' => $data->actorId,
            ]);

            $document = $this->documents->create([
                'node_id' => $node->getKey(),
                'type' => DocumentTypeKey::fromFormat($data->format),
                'schema_version' => 1,
                'created_by' => $data->actorId,
                'updated_by' => $data->actorId,
            ]);
            $document->setRelation('node', $node);

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Create,
                content: $data->content,
                actorId: $data->actorId,
                forceCheckpoint: true,
            ));
            $checkpoint = $result->checkpoint ?? throw new LogicException('[Vaulter] A new document must get its first checkpoint.');

            $node = $node->refresh();

            event(new DocumentCreated(node: $node, version: $checkpoint, actorId: $data->actorId));

            return $node;
        });
    }
}
