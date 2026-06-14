<?php

declare(strict_types=1);

namespace DocVault\Data;

final readonly class InstantiateTemplateData
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        public array $variables = [],
        public ?string $title = null,
        public ?string $type = null,
        public ?string $workspaceType = null,
        public int|string|null $workspaceId = null,
        public int|string|null $folderId = null,
        public int|string|null $actorId = null,
    ) {}
}
