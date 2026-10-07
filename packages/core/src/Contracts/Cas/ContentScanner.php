<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;

interface ContentScanner
{
    public function key(): string;

    public function version(): string;

    public function scan(ScanInput $input): ScanResult;
}
