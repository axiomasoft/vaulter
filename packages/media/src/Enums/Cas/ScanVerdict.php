<?php

declare(strict_types=1);

namespace Vaulter\Media\Enums\Cas;

use Vaulter\Attributes\Api;

#[Api]
enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
