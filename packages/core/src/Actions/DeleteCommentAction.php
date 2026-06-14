<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Models\DocumentComment;
use DocVault\Repositories\CommentStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCommentAction
{
    public function __construct(
        private CommentStoreRepository $comments,
    ) {}

    public function execute(DocumentComment $comment): void
    {
        DB::transaction(fn () => $this->comments->delete($comment));
    }
}
