<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Documents;

use Packman\Attributes\Internal;

#[Internal]
enum DocumentWriteMode: string
{
    case Snapshot = 'snapshot';
    case Journal = 'journal';
}
