<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use Vaulter\Documents\Models\Comments\NodeComment;

/** Command for ResolveCommentAction: the comment, already resolved inside its node. */
final readonly class ResolveCommentInput
{
    public function __construct(
        public NodeComment $comment,
    ) {}
}
