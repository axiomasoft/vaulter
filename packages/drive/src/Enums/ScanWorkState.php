<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums;

use Packman\Attributes\Internal;

/**
 * Where a scan obligation is in its lifecycle, as stored in v_scan_obligations.state: waiting to be claimed, held by
 * a worker under a lease, waiting out a retry delay after a failed attempt, or settled with an outcome.
 */
#[Internal]
enum ScanWorkState: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case RetryWait = 'retry_wait';
    case Settled = 'settled';
}
