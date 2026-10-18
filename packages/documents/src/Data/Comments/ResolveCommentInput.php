<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Comments\NodeComment;

/** Command for ResolveCommentAction: the comment, already resolved inside its node, and the version the caller read (checked under the row lock, CC1). */
#[Api]
final readonly class ResolveCommentInput
{
    #[Internal]
    public function __construct(
        public NodeComment $comment,
        public ?int $expectedVersion = null,
    ) {}
}
