<?php

declare(strict_types=1);

namespace Vaulter\Media\Enums\Cas;

use Vaulter\Attributes\Api;

#[Api]
enum ScanMode: string
{
    case Off = 'off';
    case NewContentOnly = 'new_content_only';
    case AllContent = 'all_content';
}
