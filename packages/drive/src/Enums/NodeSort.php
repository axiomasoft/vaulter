<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums;

use Packman\Attributes\Api;

#[Api]
enum NodeSort: string
{
    case Default = 'default';
    case Name = 'name';
    case UpdatedAt = 'updated_at';
    case Size = 'size';
}
