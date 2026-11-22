<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\DocumentWriteLock;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\ManagedDocumentCodec;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;

/**
 * Restore a document to an earlier checkpoint — copy-forward: the
 * source checkpoint is never changed; its content and Yjs state become the working
 * state and a new checkpoint records restored_from_revision_id. The collaboration
 * epoch moves, so rooms editing the replaced state cannot write it back.
 *
 * The source is resolved within the node's own aggregate, so a revision id of
 * another document (or a file revision with the same id) never restores here. One
 * outer transaction under T → N → D locks; after commit DocumentRevisionCreated fires
 * with restored=true, then DocumentRestored with the new epoch. A document with a
 * pending-state marker is refused (409 document_state_pending) and keeps its epoch.
 */
#[Internal]
final readonly class RestoreDocumentRevisionAction
{
    public function __construct(
        private DocumentRevisionReadRepository $revisions,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private EventRecorder $recorder,
        private PendingStateGuard $pending,
        private DocumentWriteLock $writeLock,
        private NodeSnapshotMapper $nodeSnapshots,
        private DocumentSnapshotMapper $documentSnapshots,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
    ) {}

    public function execute(DriveContext $context, RestoreDocumentRevisionInput $data): DocumentRevision
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
            [$node, $document] = $this->writeLock->acquire($context, $node, $nodeId);
            // Closing the generation over saved edits not in the working state would lose them: G stays.
            $this->pending->assertSettled($document);
            $source = is_int($versionId) || (is_string($versionId) && $versionId !== '') ? $this->revisions->forRestore($document, $versionId) : null;
            if (!$source instanceof DocumentRevision) {
                throw DocumentRevisionNotFoundException::forNode($nodeId, $versionId);
            }

            $content = $this->decoder->storedPayload($source->type, $source->payload?->getAttributes()['content'] ?? null, $source->payload?->content);
            $codec = $this->types->find($source->type)?->codec();
            $sync = $source->syncState;
            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Restore,
                content: is_array($content) ? ($codec instanceof ManagedDocumentCodec
                    ? $codec->decodeStored($content, $source->schema_version)
                    : OpaqueContent::fromArray($content)) : null,
                schemaVersion: $source->schema_version,
                collabState: $sync?->collab_state,
                actorId: $actorId,
                expectedStateVersion: $data->expectedStateVersion,
                forceCheckpoint: true,
                label: sprintf('Restored from #%d', $source->number),
                restoredFrom: $source,
                documentType: $source->type,
                protocol: $sync?->collab_protocol,
                stateFormat: $sync?->collab_state_format,
                writeMode: $sync === null ? DocumentWriteMode::Snapshot : null,
            ));
            $revision = $result->checkpoint ?? throw new LogicException('[Vaulter] A restore must create a checkpoint.');

            $this->recorder->record(new DocumentRevisionCreated(
                node: $node,
                version: $revision,
                stateVersion: $result->document->state_version,
                epoch: $result->document->collab_epoch,
                restored: true,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
                nodes: $this->nodeSnapshots,
                documents: $this->documentSnapshots,
            ));
            $this->recorder->record(new DocumentRestored(
                node: $node,
                version: $revision,
                restoredFrom: $source,
                epoch: $result->document->collab_epoch,
                stateVersion: $result->document->state_version,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
                nodes: $this->nodeSnapshots,
                documents: $this->documentSnapshots,
            ));

            return $revision;
        });
    }
}
