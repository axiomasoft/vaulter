<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Override;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\Content\ScanWorkSettings;

/**
 * Internal adapter exposing durable scan-work bounds from {@see DriveOptions} without leaking internal SPI types on {@see DriveOptions}.
 */
#[Internal]
final readonly class ConfiguredScanWorkSettings implements ScanWorkSettings
{
    public function __construct(private DriveOptions $options) {}

    #[Override]
    public function leaseSeconds(): int
    {
        return $this->options->uploads->scan->leaseSeconds;
    }

    #[Override]
    public function scannerTimeoutSeconds(): int
    {
        return $this->options->uploads->scan->scannerTimeoutSeconds;
    }

    #[Override]
    public function maxAttempts(): int
    {
        return $this->options->uploads->scan->maxAttempts;
    }
}
