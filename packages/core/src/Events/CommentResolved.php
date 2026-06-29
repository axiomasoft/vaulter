<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\DocumentComment;

final readonly class CommentResolved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentComment $comment,
        public int|string|null $actorId = null,
    ) {}
}
