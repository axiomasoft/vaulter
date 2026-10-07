<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Content;

/** @internal Durable scan-work tuning, supplied by the package that runs the scan jobs. */
interface ScanWorkSettings
{
    public function leaseSeconds(): int;

    public function scannerTimeoutSeconds(): int;

    public function maxAttempts(): int;
}
