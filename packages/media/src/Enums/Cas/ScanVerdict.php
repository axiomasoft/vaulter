<?php

declare(strict_types=1);

namespace Vaulter\Media\Enums\Cas;

use Packman\Attributes\Api;

#[Api]
enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
