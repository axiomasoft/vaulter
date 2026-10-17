<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\NodeName;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;
use Vaulter\Support\Identity;

/**
 * Create a kind=document node under a folder (or the storage root) with its document
 * aggregate and first checkpoint from the given content. The parent row
 * is locked and must be a live folder of the same storage; no parent means the root
 * folder, never a parentless node. One outer transaction: the node row, the
 * aggregate (working state at state version 1) and checkpoint #1 commit atomically,
 * then a single after-commit event fires.
 *
 * The node mirrors the file-node shape so the unified tree + Drive Actions (move/
 * rename/trash) apply unchanged — quota and the sibling-unique guard come for free.
 * The aggregate stores the type key and the schema version the content was decoded in.
 *
 * Authorization is enforced upstream by DocumentOperations (create on the
 * destination, profile document types); the Action only does the row work. The actor
 * is the operation's, and the storage must be the operation's drive.
 */
#[Internal]
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
        private DrivePolicyEnforcer $policy,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, CreateDocumentInput $data): Node
    {
        if (Identity::stringify($data->storage->getKey()) !== Identity::stringify($context->driveId())) {
            throw NodeNotFoundException::forId('drive');
        }
        $actorId = $context->actor->ref()->id;
        $name = $this->policy->name(drive: $data->storage, name: $data->name);
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        // the docs/adr/0004-content-format.md size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Callers
        // that decode through the type already applied its own limit.
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin($this->documentsConfig->maxContentSizeKb());
        }

        return $this->database->transaction(function () use ($context, $data, $name, $parentId, $actorId): Node {
            $this->database->lockAggregateShared('drives', $data->storage->id);
            $this->lifecycle->assertWritable($context);

            $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
            if (!$this->tree->sameStorage($parent, $data->storage->id)) {
                throw InvalidDestinationException::otherDrive(destination: $parent, storageId: $data->storage->id);
            }

            $this->tree->assertActiveFolder($parent);
            $this->policy->assertChildren(drive: $data->storage, parentId: $parentId);
            $this->policy->assertDepth(drive: $data->storage, parent: $parent);

            $name = $this->freeName($data->storage, $parentId, $name, $data->onConflict);

            $node = $this->store->create([
                'drive_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Document,
                'name' => $name,
                'mime_type' => CreateDocumentInput::NODE_MIME,
                'size_bytes' => $data->content->sizeInBytes(),
                'created_by' => $actorId,
            ]);

            $document = $this->documents->create([
                'node_id' => $node->getKey(),
                'type' => $data->type,
                'schema_version' => $data->schemaVersion,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $document->setRelation('node', $node);

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Create,
                content: $data->content,
                schemaVersion: $data->schemaVersion,
                actorId: $actorId,
                forceCheckpoint: true,
            ));
            $checkpoint = $result->checkpoint ?? throw new LogicException('[Vaulter] A new document must get its first checkpoint.');

            $node = $node->refresh();

            $this->recorder->record(new DocumentCreated(
                node: $node,
                version: $checkpoint,
                stateVersion: $result->document->state_version,
                epoch: $result->document->collab_epoch,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
            ));

            return $node;
        });
    }

    /**
     * The name itself, or on Rename the first free "<stem> (N)<.ext>". Caller holds the
     * tree and parent locks. (Documents cannot depend on drive's NameConflictResolver.)
     */
    private function freeName(Drive $storage, int|string $parentId, string $name, OnNameConflict $onConflict): string
    {
        $name = NodeName::normalize($name);
        $candidate = $name;
        for ($i = 2; $i <= 7; $i++) {
            if (!$this->nodes->siblingNameExists(storage: $storage, parentId: $parentId, name: $candidate)) {
                // Truncation plus a long extension can produce a name that no
                // longer fits; generated names obey the same policy as input.
                return $candidate === $name ? $candidate : $this->policy->name($storage, $candidate);
            }
            if ($onConflict !== OnNameConflict::Rename || $i === 7) {
                break;
            }
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
            $suffix = ' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
            $candidate = mb_strcut($stem, 0, NodeName::MAX_BYTES - strlen($suffix), 'UTF-8').$suffix;
        }

        throw NodeNameConflictException::forName($name, $parentId);
    }
}
