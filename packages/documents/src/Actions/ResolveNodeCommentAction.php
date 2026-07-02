<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Events\NodeCommentResolved;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Documents\Repositories\NodeCommentStoreRepository;

/**
 * Mark a node comment thread resolved (ADR-0014 шаг 1). Stamps (resolved_at,
 * resolved_by) in a transaction, then dispatches {@see NodeCommentResolved} after
 * commit.
 */
final readonly class ResolveNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
    ) {}

    public function execute(NodeComment $comment, int|string|null $resolvedBy = null): NodeComment
    {
        $resolved = DB::transaction(
            fn (): NodeComment => $this->comments->resolve($comment, $resolvedBy),
        );

        event(new NodeCommentResolved($resolved, $resolvedBy));

        return $resolved;
    }
}
