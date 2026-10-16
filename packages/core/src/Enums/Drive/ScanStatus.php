<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

use Vaulter\Attributes\Api;

/**
 * Malware-scan state of a file revision, as stored in its scan_status column.
 *
 * @closed
 */
#[Api]
enum ScanStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
