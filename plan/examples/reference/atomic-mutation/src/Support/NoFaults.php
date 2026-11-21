<?php

declare(strict_types=1);

namespace VaulterRef\Support;

use VaulterRef\Database\Pg;

final readonly class NoFaults implements FaultPoint
{
    public function reach(string $point, ?Pg $db = null): void {}
}
