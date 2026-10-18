<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Access;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * How far a change of effective permissions reaches (`drive.access.effective_permissions_changed`): one node, a node
 * and everything under it, a whole drive, or every drive (a host role or tenant change the drive cannot narrow).
 */
#[Api]
enum InvalidationScope: string
{
    use HasLabel;

    #[Label('One node')]
    case Node = 'node';

    #[Label('A node and everything in it')]
    case Subtree = 'subtree';

    #[Label('A whole drive')]
    case Drive = 'drive';

    #[Label('Every drive')]
    case Global = 'global';
}
