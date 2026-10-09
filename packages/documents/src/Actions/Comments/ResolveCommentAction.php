<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Events\Comments\CommentResolved;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\DriveLifecycle;
use Vaulter\Support\TreeGuard;

/**
 * Mark a node comment thread resolved (ADR-0014 шаг 1). Stamps (resolved_at,
 * resolved_by = the operation's actor, null for the system) under T (shared) → N → the
 * comment row, with the drive writable and the node live ({@see AddCommentAction}).
 * {@see CommentResolved} is delivered after the outermost commit.
 */
final readonly class ResolveCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveLifecycle $lifecycle,
    ) {}

    public function execute(OperationContext $context, ResolveCommentInput $data): NodeComment
    {
        $resolvedBy = $context->actor->ref()->id;

        return $this->database->transaction(function () use ($context, $data, $resolvedBy): NodeComment {
            $driveId = $context->driveId();
            $nodeId = $data->comment->node_id;
            $this->database->lockTreeShared($driveId);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            $this->tree->assertLive($current);
            $row = $this->comments->lock($data->comment) ?? throw CommentNotFoundException::forNode($nodeId, $data->comment->getKey());

            $resolved = $this->comments->resolve($row, $resolvedBy);
            event(new CommentResolved($resolved, $resolvedBy));

            return $resolved;
        });
    }
}
