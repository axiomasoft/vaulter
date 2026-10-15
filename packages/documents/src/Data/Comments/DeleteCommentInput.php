<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Comments\NodeComment;

/** Command for DeleteCommentAction: the comment, already resolved inside its node. */
#[Api]
final readonly class DeleteCommentInput
{
    #[Internal]
    public function __construct(
        public NodeComment $comment,
    ) {}
}
