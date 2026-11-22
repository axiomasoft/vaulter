<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentCommandRequiredException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;
use Vaulter\Http\Support\RepresentationTag;
use Vaulter\Support\Identity;

/**
 * Write a document node's content — the autosave path. The working
 * state is replaced; an autosave checkpoint is taken only when the checkpoint
 * interval has passed, so a typing session does not flood the history and no
 * stored checkpoint is ever rewritten. A snapshot edit keeps its generation.
 * The locked document must be editable and in snapshot write mode.
 *
 * One outer transaction under T → N → D locks; a single after-commit event when the
 * write changed something. A document with a pending-state marker is refused (409
 * document_state_pending): the caller drains it outside the transaction (PendingStateGuard).
 */
#[Internal]
final readonly class UpdateDocumentContentAction
{
    public function __construct(
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private ContentDecoder $decoder,
        private EventRecorder $recorder,
        private PendingStateGuard $pending,
        private DocumentWriteLock $writeLock,
        private NodeSnapshotMapper $nodeSnapshots,
        private DocumentSnapshotMapper $documentSnapshots,
    ) {}

    /**
     * @throws DocumentTypeUnavailableException
     * @throws DocumentContentDegradedException
     * @throws DocumentStatePendingException    the document carries a pending-state marker
     */
    public function execute(DriveContext $context, UpdateDocumentContentInput $data): DocumentStateResult
    {
        $node = $data->node;

        return $this->database->transaction(function () use ($context, $node, $data): DocumentStateResult {
            // The node must live in the operation's drive; the locked row is checked below.
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            [$node, $document] = $this->writeLock->acquire($context, $node, $nodeId);
            if ($document->write_mode === DocumentWriteMode::Journal) {
                throw new DocumentCommandRequiredException;
            }
            // Saved collaboration edits the working state does not hold yet would be overwritten.
            $this->pending->assertSettled($document);
            if ($data->matchesTag !== null && !($data->matchesTag)(RepresentationTag::document(Identity::stringify($nodeId), $document->collab_epoch, $document->state_version))) {
                throw new StaleDocumentStateException($document->state_version, $document->state_version, Identity::stringify($nodeId));
            }
            // A restore may have committed between the caller's check and this lock.
            $type = $this->decoder->editableType($document);

            $sync = $document->syncState;
            if ($sync?->collab_protocol !== null && ($type->schemaVersion() !== $document->schema_version
                || !$type instanceof SupportsCollaboration
                || $type->collaborationProtocol() !== $sync->collab_protocol || $type->collaborationStateFormat() !== $sync->collab_state_format)) {
                throw new CollaborationUnavailableException('unsupported_binding', $document->type);
            }
            $raw = $data->content instanceof DocumentContent ? $data->content->toArray() : $data->content;
            $decoded = $this->decoder->decode($type, $raw);
            if ($document->type === RichTextDocumentType::KEY && $type->schemaVersion() >= 2 && $decoded instanceof TipTapContent) {
                BlockIdentity::assertComplete($decoded->toArray());
            }

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Update,
                content: $decoded,
                schemaVersion: $type->schemaVersion(),
                actorId: $context->actor->ref()->id,
                expectedStateVersion: $data->expectedStateVersion,
            ));

            $head = $result->checkpoint ?? $document->currentRevision()->sharedLock()->firstOrFail();
            if ($result->checkpoint === null) {
                $head->setRelation('payload', $head->payload()->sharedLock()->first(['revision_id', 'content_hash', 'content_size']));
                $head->setRelation('syncState', $head->syncState()->sharedLock()->first(['revision_id', 'collab_protocol', 'collab_state_format', 'collab_state_size']));
            }
            $document->setRelation('currentRevision', $head);

            if ($result->changed) {
                $this->recorder->record(new DocumentContentUpdated(
                    node: $node,
                    version: $head,
                    created: $result->checkpoint !== null,
                    stateVersion: $result->document->state_version,
                    epoch: $result->document->collab_epoch,
                    actor: $context->actor->ref(),
                    correlationId: $context->correlationId,
                    nodes: $this->nodeSnapshots,
                    documents: $this->documentSnapshots,
                ));
            }

            return $result;
        });
    }
}
