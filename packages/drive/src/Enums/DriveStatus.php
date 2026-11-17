<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums;

use Packman\Attributes\Api;

/**
 * The lifecycle of a drive. An archived drive is read-only; a drive
 * being deleted is invisible to everyone but the system purge. Clients must treat a
 * value they do not know as unavailable.
 *
 * @closed
 */
#[Api]
enum DriveStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleting = 'deleting';
}
