<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Rules\CommentAnchorBounds;

/**
 * What a new comment says: the body, the optional parent it replies to (a comment of
 * the same node) and the optional anchor into the content. The author is the actor of
 * the operation — it is never taken from the input. An anchor must encode as valid JSON
 * within 16 levels and 16384 bytes using PHP's default JSON escaping (including UTF-8
 * escapes). Invalid anchors are rejected before an operation can fingerprint or write them.
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
    ) {
        $error = CommentAnchorBounds::error($anchor);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }
}
