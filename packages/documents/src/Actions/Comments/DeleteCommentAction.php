<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Events\Comments\CommentDeleted;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

/**
 * Permanently delete a node comment (docs/adr/0014-legacy-docvault-strangler.md шаг 1 — no soft-delete), under T
 * (shared) → N → the comment row with the drive writable and the node live
 * ({@see AddCommentAction}): deleting a comment is a mutation, whoever wrote it.
 * {@see CommentDeleted} is delivered after the outermost commit; the in-memory model
 * still carries its id/node_id for the broadcast payload.
 */
#[Internal]
final readonly class DeleteCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
        private AggregateVersion $versions,
        private DocumentSnapshotMapper $documentSnapshots,
    ) {}

    public function execute(DriveContext $context, DeleteCommentInput $data): void
    {
        $this->database->transaction(function () use ($context, $data): void {
            $driveId = $context->driveId();
            $nodeId = $data->comment->node_id;
            $this->database->lockAggregateShared('drives', $driveId);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            $this->tree->assertLive($current);
            $row = $this->comments->lock($data->comment) ?? throw CommentNotFoundException::forNode($nodeId, $data->comment->getKey());
            $this->versions->assertExpected($row, 'version', $data->expectedVersion, NodeComment::AGGREGATE);

            $this->comments->delete($row);
            $row->setRelation('node', $current);
            $this->recorder->record(new CommentDeleted(comment: $row, actor: $context->actor->ref(), correlationId: $context->correlationId, documents: $this->documentSnapshots));
        });
    }
}
