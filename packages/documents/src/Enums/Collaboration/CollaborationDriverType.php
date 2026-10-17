<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Collaboration;

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
