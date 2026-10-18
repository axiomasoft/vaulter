<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Enums;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

#[Api]
enum CollaborationDriverType: string
{
    use HasLabel;

    #[Label('Hocuspocus')]
    case Hocuspocus = 'hocuspocus';
}
