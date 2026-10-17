<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Content;

use Vaulter\Attributes\Internal;

/** @internal Durable scan-work tuning, supplied by the package that runs the scan jobs. */
#[Internal]
interface ScanWorkSettings
{
    public function leaseSeconds(): int;

    public function scannerTimeoutSeconds(): int;

    public function maxAttempts(): int;
}
