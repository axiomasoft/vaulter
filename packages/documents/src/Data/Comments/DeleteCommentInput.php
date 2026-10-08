<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use Vaulter\Documents\Models\Comments\NodeComment;

/** Command for DeleteCommentAction: the comment, already resolved inside its node. */
final readonly class DeleteCommentInput
{
    public function __construct(
        public NodeComment $comment,
    ) {}
}
