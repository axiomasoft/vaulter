<?php

declare(strict_types=1);

namespace DocVault\Enums;

use DocVault\Attributes\Label;
use DocVault\Support\Concerns\HasLabel;

enum CollaborationDriverType: string
{
    use HasLabel;

    #[Label('Laravel Reverb')]
    case Reverb = 'reverb';

    #[Label('Hocuspocus')]
    case Hocuspocus = 'hocuspocus';
}
