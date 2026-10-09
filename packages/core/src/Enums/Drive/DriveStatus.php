<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

/**
 * The lifecycle of a drive (PLAN2.D27/D37). An archived drive is read-only; a drive
 * being deleted is invisible to everyone but the system purge. Clients must treat a
 * value they do not know as unavailable.
 *
 * @closed
 */
enum DriveStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleting = 'deleting';
}
