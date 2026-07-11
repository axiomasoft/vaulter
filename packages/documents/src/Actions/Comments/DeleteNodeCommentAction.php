<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Events\Comments\NodeCommentDeleted;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;

/**
 * Permanently delete a node comment (ADR-0014 шаг 1 — no soft-delete). Deletes in a
 * transaction, then dispatches {@see NodeCommentDeleted} after commit (the in-memory
 * model still carries its id/node_id for the broadcast payload).
 */
final readonly class DeleteNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
    ) {}

    public function execute(NodeComment $comment, int|string|null $actorId = null): void
    {
        DB::transaction(fn () => $this->comments->delete($comment));

        event(new NodeCommentDeleted($comment, $actorId));
    }
}
