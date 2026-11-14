<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\DocumentWriteLock;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;

/**
 * Checkpoint a document's current working state as a NAMED (non-autosave) revision
 * with a label — even when the working state did not change since the
 * last checkpoint. The named revision becomes the head; keepForever pins it out of
 * the prune window. The working state and its validators are untouched, so a
 * snapshot needs no precondition. One outer transaction under T → N → D locks; a
 * single after-commit event fires.
 */
#[Internal]
final readonly class CreateNamedRevisionAction
{
    public function __construct(
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private EventRecorder $recorder,
        private DocumentWriteLock $writeLock,
    ) {}

    public function execute(DriveContext $context, CreateNamedRevisionInput $data): DocumentRevision
    {
        $node = $data->node;

        return $this->database->transaction(function () use ($context, $node, $data): DocumentRevision {
            // The node must live in the operation's drive; the locked row is checked below.
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            [$node, $document] = $this->writeLock->acquire($context, $node, $nodeId);

            $revision = $this->writer->checkpoint(
                locked: $document,
                label: $data->label,
                keepForever: $data->keepForever,
                actorId: $context->actor->ref()->id,
            );

            $this->recorder->record(new DocumentRevisionCreated(
                node: $node,
                version: $revision,
                stateVersion: $document->state_version,
                epoch: $document->collab_epoch,
                restored: false,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
            ));

            return $revision;
        });
    }
}
