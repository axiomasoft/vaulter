<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use Vaulter\Attributes\Api;

/**
 * What a new comment says: the body, the optional parent it replies to (a comment of
 * the same node) and the optional anchor into the content. The author is the actor of
 * the operation — it is never taken from the input.
 */
#[Api]
final readonly class AddCommentInput
{
    /**
     * @param array<string, mixed>|null $anchor
     */
    public function __construct(
        public string $body,
        public int|string|null $parentId = null,
        public ?array $anchor = null,
    ) {}
}
