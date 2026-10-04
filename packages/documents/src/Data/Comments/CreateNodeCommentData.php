<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

/**
 * The input to {@see AddNodeCommentAction} (ADR-0014 шаг 1).
 * authorType is a REGISTERED morph alias ($user->getMorphClass()), never a raw FQCN —
 * the HTTP request layer resolves it.
 */
final readonly class CreateNodeCommentData
{
    /**
     * @param array<string, mixed>|null $anchor
     */
    public function __construct(
        public string $body,
        public string $authorType,
        public int|string $authorId,
        public int|string|null $parentId = null,
        public ?array $anchor = null,
    ) {}
}
