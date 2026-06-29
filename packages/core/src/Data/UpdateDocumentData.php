<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\ValueObjects\TipTapContent;

/**
 * Partial update — null fields are left unchanged.
 */
final readonly class UpdateDocumentData
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public ?string $title = null,
        public ?TipTapContent $content = null,
        public ?array $meta = null,
        public int|string|null $actorId = null,
    ) {}
}
