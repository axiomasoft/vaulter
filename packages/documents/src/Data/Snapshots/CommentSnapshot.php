<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;

/** Read-only view of a comment on a node. The author is the host user's morph type and key. */
final readonly class CommentSnapshot
{
    /**
     * @internal
     *
     * @param array<string, mixed>|null $anchor
     */
    public function __construct(
        public string $id,
        public string $nodeId,
        public ?string $parentId,
        public string $authorType,
        public string $authorId,
        public string $body,
        public ?array $anchor,
        public ?DateTimeImmutable $resolvedAt,
        public ?string $resolvedBy,
        public DateTimeImmutable $createdAt,
    ) {}
}
