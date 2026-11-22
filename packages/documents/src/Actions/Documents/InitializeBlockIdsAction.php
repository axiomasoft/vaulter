<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\DocumentCommandRequiredException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;
use Vaulter\Support\Identity;

/**
 * Authorized CAS normalization of a rich-text document from schema v1 to v2: assigns
 * unique `attrs.blockId` values to every addressable block (`paragraph`, `heading`).
 *
 * Competing normalizations serialize on the T → N → D write lock and validate
 * `expectedStateVersion` via CAS (`StaleDocumentStateException`). Repeating normalization
 * on an already-normalized v2 document is a no-op and never creates a second version.
 */
#[Internal]
final readonly class InitializeBlockIdsAction
{
    public function __construct(
        private VaulterDatabase $database,
        private DocumentWriteLock $lock,
        private OperationGuard $guard,
        private PendingStateGuard $pending,
        private ContentDecoder $decoder,
        private DocumentStateWriter $writer,
        private EventRecorder $recorder,
        private NodeSnapshotMapper $nodeSnapshots,
        private DocumentSnapshotMapper $documentSnapshots,
    ) {}

    public function execute(DriveContext $context, Node $node, ?int $expectedStateVersion = null): DocumentStateResult
    {
        return $this->database->transaction(function () use ($context, $node, $expectedStateVersion): DocumentStateResult {
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }

            [$lockedNode, $document] = $this->lock->acquire($context, $node, $nodeId);
            $this->guard->authorize($context, Ability::Edit, $lockedNode, OperationKind::Mutation);

            if ($document->write_mode === DocumentWriteMode::Journal) {
                throw new DocumentCommandRequiredException;
            }

            $this->pending->assertSettled($document);

            if ($expectedStateVersion !== null && $document->state_version !== $expectedStateVersion) {
                throw new StaleDocumentStateException($expectedStateVersion, $document->state_version, Identity::stringify($nodeId));
            }

            if ($document->type !== RichTextDocumentType::KEY) {
                return new DocumentStateResult(changed: false, document: $document, checkpoint: null, epochChanged: false);
            }

            $type = $this->decoder->editableType($document);
            if ($type->schemaVersion() < 2) {
                return new DocumentStateResult(changed: false, document: $document, checkpoint: null, epochChanged: false);
            }

            $raw = $document->payload?->content;
            if (!is_array($raw) || ($raw['type'] ?? null) !== 'doc') {
                $raw = TipTapContent::empty()->toArray();
            }

            $normalized = BlockIdentity::normalize($raw);
            if ($document->schema_version >= 2 && !$normalized['changed']) {
                return new DocumentStateResult(changed: false, document: $document, checkpoint: null, epochChanged: false);
            }

            $content = TipTapContent::fromArray($normalized['doc'], TipTapSchema::richText());
            $sync = $document->syncState;
            $isBound = $sync?->collab_protocol !== null;
            $result = $this->writer->write($document, new DocumentStateChange(
                cause: $isBound ? StateWriteCause::Transition : StateWriteCause::Update,
                content: $content,
                schemaVersion: max(2, $document->schema_version),
                actorId: $context->actor->ref()->id,
                expectedStateVersion: $expectedStateVersion ?? $document->state_version,
                documentType: $isBound ? $document->type : null,
                protocol: $isBound ? $sync->collab_protocol : null,
                stateFormat: $isBound ? $sync->collab_state_format : null,
            ));

            $head = $result->checkpoint ?? $document->currentRevision()->sharedLock()->firstOrFail();
            if ($result->checkpoint === null) {
                $head->setRelation('payload', $head->payload()->sharedLock()->first(['revision_id', 'content_hash', 'content_size']));
                $head->setRelation('syncState', $head->syncState()->sharedLock()->first(['revision_id', 'collab_protocol', 'collab_state_format', 'collab_state_size']));
            }
            $document->setRelation('currentRevision', $head);

            if ($result->changed) {
                $this->recorder->record(new DocumentContentUpdated(
                    node: $lockedNode,
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
