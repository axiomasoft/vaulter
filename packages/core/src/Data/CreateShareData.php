<?php

declare(strict_types=1);

namespace DocVault\Data;

use DateTimeInterface;
use DocVault\Enums\PermissionLevel;

final readonly class CreateShareData
{
    public function __construct(
        public PermissionLevel $level,
        public ?DateTimeInterface $expiresAt = null,
        public int|string|null $createdBy = null,
    ) {}
}
