<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Changes;

use Packman\Attributes\Api;

/**
 * Why a node is removed from a reader's view. Only `deleted` is stored;
 * the other two come from the read projection, when the node still exists but the
 * reader may no longer see it.
 *
 *   deleted          the node went to the trash (or was purged)
 *   moved_out        the node was moved where the reader cannot see it
 *   permission_lost  an access change took the node away from the reader
 *
 * @closed The string values are stored and never change.
 */
#[Api]
enum ChangeReason: string
{
    case Deleted = 'deleted';
    case MovedOut = 'moved_out';
    case PermissionLost = 'permission_lost';
}
