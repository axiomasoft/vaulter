<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;

#[Spi(SpiKind::Implement)]
interface ContentScanner
{
    public function key(): string;

    public function version(): string;

    public function scan(ScanInput $input): ScanResult;
}
