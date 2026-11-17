<?php

declare(strict_types=1);

namespace Vaulter\Media\Contracts\Cas;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;

#[Spi(SpiKind::Implement)]
interface ContentScanner
{
    public function key(): string;

    public function version(): string;

    public function scan(ScanInput $input): ScanResult;
}
