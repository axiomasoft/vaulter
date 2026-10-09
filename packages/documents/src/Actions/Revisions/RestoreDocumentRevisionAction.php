<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use LogicException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\TreeGuard;

/**
 * Restore a document to an earlier checkpoint (§2.5 / M4a) — copy-forward: the
 * source checkpoint is never changed; its content and Yjs state become the working
 * state and a new checkpoint records restored_from_revision_id. The collaboration
 * epoch moves, so rooms editing the replaced state cannot write it back.
 *
 * The source is resolved within the node's own aggregate, so a revision id of
 * another document (or a file revision with the same id) never restores here. One
 * outer transaction under T → N → D locks; after commit DocumentRevisionCreated fires
 * with restored=true, then DocumentRestored with the new epoch.
 */
final readonly class RestoreDocumentRevisionAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentRevisionReadRepository $revisions,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(OperationContext $context, RestoreDocumentRevisionInput $data): DocumentRevision
    {
        $node = $data->node;
        $versionId = $data->revision instanceof DocumentRevision ? $data->revision->getKey() : $data->revision;
        $actorId = $context->actor->ref()->id;

        return $this->database->transaction(function () use ($context, $node, $versionId, $actorId, $data): DocumentRevision {
            // The node must live in the operation's drive; the locked row is checked below.
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            $this->database->lockTreeShared($driveId);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            if (!$current->kind->isDocument()) {
                throw NotADocumentException::forNode($nodeId);
            }
            $node = $this->tree->sync($node, $current);
            $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
            $document->setRelation('node', $node);
            $source = is_int($versionId) || (is_string($versionId) && $versionId !== '') ? $this->revisions->forNode($node, $versionId) : null;
            if (!$source instanceof DocumentRevision) {
                throw DocumentRevisionNotFoundException::forNode($nodeId, $versionId);
            }

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Restore,
                content: OpaqueContent::fromArray($source->content ?? []),
                yjsState: $source->yjs_state,
                schemaVersion: $source->schema_version,
                actorId: $actorId,
                expectedStateVersion: $data->expectedStateVersion,
                forceCheckpoint: true,
                label: sprintf('Restored from #%d', $source->number),
                restoredFrom: $source,
            ));
            $revision = $result->checkpoint ?? throw new LogicException('[Vaulter] A restore must create a checkpoint.');

            event(new DocumentRevisionCreated(
                node: $node,
                version: $revision,
                restored: true,
                actorId: $actorId,
            ));
            event(new DocumentRestored(
                node: $node,
                version: $revision,
                restoredFrom: $source,
                epoch: $result->document->collab_epoch,
                stateVersion: $result->document->state_version,
                actorId: $actorId,
            ));

            return $revision;
        });
    }
}
