<?php

declare(strict_types=1);

namespace Vaulter\Data;

final readonly class CreateFolderData
{
    public function __construct(
        public string $workspaceType,
        public int|string $workspaceId,
        public string $name,
        public int|string|null $parentId = null,
        public int $order = 0,
    ) {}
}
