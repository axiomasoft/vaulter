<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

use Vaulter\Attributes\Api;

#[Api]
enum NodeSort: string
{
    case Default = 'default';
    case Name = 'name';
    case UpdatedAt = 'updated_at';
    case Size = 'size';
}
