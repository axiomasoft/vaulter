<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

enum OnNameConflict: string
{
    case Fail = 'fail';
    case Rename = 'rename';
    case AddRevision = 'add_revision';
}
