<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\CommentResolved;
use DocVault\Models\DocumentComment;
use DocVault\Repositories\CommentStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class ResolveCommentAction
{
    public function __construct(
        private CommentStoreRepository $comments,
    ) {}

    public function execute(DocumentComment $comment, int|string|null $actorId = null): DocumentComment
    {
        $resolved = DB::transaction(fn (): DocumentComment => $this->comments->resolve($comment));

        event(new CommentResolved($resolved, $actorId));

        return $resolved;
    }
}
