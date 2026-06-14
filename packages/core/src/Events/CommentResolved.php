<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\DocumentComment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class CommentResolved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentComment $comment,
        public int|string|null $actorId = null,
    ) {}
}
