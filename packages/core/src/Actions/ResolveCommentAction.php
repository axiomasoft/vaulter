<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\CommentResolved;
use Vaulter\Models\DocumentComment;
use Vaulter\Repositories\CommentStoreRepository;

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
