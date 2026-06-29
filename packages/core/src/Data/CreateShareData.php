<?php

declare(strict_types=1);

namespace Vaulter\Data;

use DateTimeInterface;
use Vaulter\Enums\PermissionLevel;

final readonly class CreateShareData
{
    public function __construct(
        public PermissionLevel $level,
        public ?DateTimeInterface $expiresAt = null,
        public int|string|null $createdBy = null,
    ) {}
}
