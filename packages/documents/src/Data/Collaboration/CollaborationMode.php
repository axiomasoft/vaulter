<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Packman\Attributes\Api;

#[Api]
enum CollaborationMode: string
{
    case Read = 'read';
    case Write = 'write';
}
