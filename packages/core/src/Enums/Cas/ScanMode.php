<?php

declare(strict_types=1);

namespace Vaulter\Enums\Cas;

enum ScanMode: string
{
    case Off = 'off';
    case NewContentOnly = 'new_content_only';
    case AllContent = 'all_content';
}
