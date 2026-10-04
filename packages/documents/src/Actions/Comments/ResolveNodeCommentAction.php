<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Events\Comments\NodeCommentResolved;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;

/**
 * Mark a node comment thread resolved (ADR-0014 шаг 1). Stamps (resolved_at,
 * resolved_by) in a transaction, then dispatches {@see NodeCommentResolved} after
 * commit.
 */
final readonly class ResolveNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(NodeComment $comment, int|string|null $resolvedBy = null): NodeComment
    {
        $resolved = $this->database->transaction(
            fn (): NodeComment => $this->comments->resolve($comment, $resolvedBy),
        );

        event(new NodeCommentResolved($resolved, $resolvedBy));

        return $resolved;
    }
}
