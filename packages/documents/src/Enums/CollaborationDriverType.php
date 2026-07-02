<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

enum CollaborationDriverType: string
{
    use HasLabel;

    #[Label('Laravel Reverb')]
    case Reverb = 'reverb';

    #[Label('Hocuspocus')]
    case Hocuspocus = 'hocuspocus';
}
