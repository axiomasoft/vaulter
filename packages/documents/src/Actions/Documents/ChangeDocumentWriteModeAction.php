<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Access\CollaborationAccessPolicy;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Collaboration\CollaborationAccessDecision;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;

#[Internal]
final readonly class ChangeDocumentWriteModeAction
{
    public function __construct(
        private VaulterDatabase $database,
        private DocumentWriteLock $lock,
        private OperationGuard $guard,
        private CollaborationAccessPolicy $policy,
        private PendingStateGuard $pending,
        private DocumentStateWriter $writer,
        private EventRecorder $recorder,
        private NodeSnapshotMapper $nodeSnapshots,
        private DocumentSnapshotMapper $documentSnapshots,
    ) {}

    /** Reserve the journal writer generation for a compatible, authorized collaboration session. */
    public function execute(DriveContext $context, Node $node, int $expectedGeneration, int $expectedPosition, ?int $clientSchemaVersion = null): CollaborationAccessDecision
    {
        return $this->database->transaction(function () use ($context, $node, $expectedGeneration, $expectedPosition, $clientSchemaVersion): CollaborationAccessDecision {
            [$lockedNode, $document] = $this->lock->acquire($context, $node, $node->id);
            $this->guard->authorize($context, Ability::View, $lockedNode, OperationKind::Metadata);
            $decision = $this->policy->evaluateState($context, $lockedNode, $document, $clientSchemaVersion);
            if ($decision->grant->mode !== CollaborationMode::Write || $document->write_mode === DocumentWriteMode::Journal) {
                // A competing session may already have opened the shared generation.
                return $decision;
            }
            $this->guard->authorize($context, Ability::Edit, $lockedNode, OperationKind::Mutation);
            $this->pending->assertSettled($document);
            if ($document->collab_epoch !== $expectedGeneration || $document->state_version !== $expectedPosition) {
                throw new StaleDocumentStateException($expectedPosition, $document->state_version, $decision->grant->nodeId);
            }

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Transition,
                content: null,
                schemaVersion: $document->schema_version,
                collabState: $document->syncState?->collab_state,
                actorId: $context->actor->ref()->id,
                expectedStateVersion: $expectedPosition,
                forceCheckpoint: true,
                protocol: $decision->grant->protocol,
                stateFormat: $decision->grant->stateFormat,
                writeMode: DocumentWriteMode::Journal,
            ));

            $this->recorder->record(new DocumentContentUpdated(
                node: $lockedNode,
                version: $result->checkpoint ?? throw new LogicException('[Vaulter] A write-mode transition requires a checkpoint.'),
                created: true,
                stateVersion: $document->state_version,
                epoch: $document->collab_epoch,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
                nodes: $this->nodeSnapshots,
                documents: $this->documentSnapshots,
            ));

            return $this->policy->evaluateState($context, $lockedNode, $document, $clientSchemaVersion);
        });
    }
}
