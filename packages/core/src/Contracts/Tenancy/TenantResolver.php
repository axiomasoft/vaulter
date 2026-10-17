<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Tenancy;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

#[Spi(SpiKind::Implement)]
interface TenantResolver
{
    public function current(): int|string|null;
}
