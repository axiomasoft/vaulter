<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Tenancy;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Implement)]
interface TenantResolver
{
    public function current(): int|string|null;
}
