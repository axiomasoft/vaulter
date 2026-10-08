<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Events\Comments\CommentResolved;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Operations\OperationContext;

/**
 * Mark a node comment thread resolved (ADR-0014 шаг 1). Stamps (resolved_at,
 * resolved_by = the operation's actor, null for the system) in a transaction, then
 * dispatches {@see CommentResolved} after commit.
 */
final readonly class ResolveCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(OperationContext $context, ResolveCommentInput $data): NodeComment
    {
        $resolvedBy = $context->actor->ref()->id;
        $resolved = $this->database->transaction(
            fn (): NodeComment => $this->comments->resolve($data->comment, $resolvedBy),
        );

        event(new CommentResolved($resolved, $resolvedBy));

        return $resolved;
    }
}
