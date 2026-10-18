<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * Read-only view of a comment on a node. The author is the host user's morph type and key. `version` rises with the
 * resolution: pass it back as the expected version of resolve() and delete().
 */
#[Api]
final readonly class CommentSnapshot
{
    /**
     * @param array<string, mixed>|null $anchor
     */
    #[Internal]
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
        public int $version = 0,
    ) {}
}
