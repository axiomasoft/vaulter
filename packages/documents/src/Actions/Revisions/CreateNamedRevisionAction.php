<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\TreeGuard;

/**
 * Checkpoint a document's current working state as a NAMED (non-autosave) revision
 * with a label (§2.5 / M4a) — even when the working state did not change since the
 * last checkpoint. The named revision becomes the head; keepForever pins it out of
 * the prune window. The working state and its validators are untouched, so a
 * snapshot needs no precondition. One outer transaction under T → N → D locks; a
 * single after-commit event fires.
 */
final readonly class CreateNamedRevisionAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(OperationContext $context, CreateNamedRevisionInput $data): DocumentRevision
    {
        $node = $data->node;

        return $this->database->transaction(function () use ($context, $node, $data): DocumentRevision {
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

            $revision = $this->writer->checkpoint(
                locked: $document,
                label: $data->label,
                keepForever: $data->keepForever,
                actorId: $context->actor->ref()->id,
            );

            event(new DocumentRevisionCreated(
                node: $node,
                version: $revision,
                restored: false,
                actorId: $context->actor->ref()->id,
            ));

            return $revision;
        });
    }
}
