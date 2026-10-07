<?php

declare(strict_types=1);

namespace Vaulter\Enums\Cas;

enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
