<?php

declare(strict_types=1);

namespace DocVault\Data;

use DocVault\Enums\DocumentType;
use DocVault\ValueObjects\TipTapContent;

final readonly class CreateDocumentData
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public string $workspaceType,
        public int|string $workspaceId,
        public DocumentType $type,
        public string $title,
        public TipTapContent $content,
        public int|string|null $folderId = null,
        public ?array $meta = null,
        public int|string|null $actorId = null,
    ) {}
}
