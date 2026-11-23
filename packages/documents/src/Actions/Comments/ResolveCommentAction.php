<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Events\Comments\CommentResolved;
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
 * Change a node comment thread's resolution. Stamps or clears (resolved_at,
 * resolved_by = the operation's actor, null for the system) under T (shared) → N → the
 * comment row, with the drive writable and the node live ({@see AddCommentAction}).
 * {@see CommentResolved} is delivered after the outermost commit.
 */
#[Internal]
final readonly class ResolveCommentAction
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

    public function execute(DriveContext $context, ResolveCommentInput $data, bool $reopen = false): NodeComment
    {
        $resolvedBy = $context->actor->ref()->id;

        return $this->database->transaction(function () use ($context, $data, $resolvedBy, $reopen): NodeComment {
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
            // The version the caller read is compared on the locked row, so a delete or a resolution that landed
            // in between is a 412, never a silent second write.
            $this->versions->assertExpected($row, 'version', $data->expectedVersion, NodeComment::AGGREGATE);

            $resolved = $reopen ? $this->comments->reopen($row) : $this->comments->resolve($row, $resolvedBy);
            $this->versions->bump($resolved, 'version', NodeComment::AGGREGATE);
            $resolved->setRelation('node', $current);
            if (!$reopen) {
                $this->recorder->record(new CommentResolved(comment: $resolved, actor: $context->actor->ref(), correlationId: $context->correlationId, documents: $this->documentSnapshots));
            }

            return $resolved;
        });
    }
}
