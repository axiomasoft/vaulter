<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Events\Comments\CommentDeleted;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Operations\OperationContext;

/**
 * Permanently delete a node comment (ADR-0014 шаг 1 — no soft-delete). Deletes in a
 * transaction, then dispatches {@see CommentDeleted} after commit (the in-memory
 * model still carries its id/node_id for the broadcast payload).
 */
final readonly class DeleteCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(OperationContext $context, DeleteCommentInput $data): void
    {
        $this->database->transaction(fn () => $this->comments->delete($data->comment));

        event(new CommentDeleted($data->comment, $context->actor->ref()->id));
    }
}
