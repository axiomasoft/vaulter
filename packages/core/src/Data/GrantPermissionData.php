<?php

declare(strict_types=1);

namespace DocVault\Data;

use DocVault\Enums\PermissionLevel;

final readonly class GrantPermissionData
{
    public function __construct(
        public string $subjectType,
        public int|string $subjectId,
        public PermissionLevel $level,
        public int|string|null $grantedBy = null,
    ) {}
}
