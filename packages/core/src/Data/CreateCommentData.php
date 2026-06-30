<?php

declare(strict_types=1);

namespace Vaulter\Data;

final readonly class CreateCommentData
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
