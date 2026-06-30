<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\Documents\DocumentComment;
use Vaulter\Repositories\Documents\CommentStoreRepository;

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
