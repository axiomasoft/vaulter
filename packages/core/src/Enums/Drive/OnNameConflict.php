<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

use Vaulter\Attributes\Api;

#[Api]
enum OnNameConflict: string
{
    case Fail = 'fail';
    case Rename = 'rename';
    case AddRevision = 'add_revision';
}
