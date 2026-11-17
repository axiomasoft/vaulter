<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Implement)]
interface ResolvesOwnerTenant
{
    public function tenantOf(Model $owner): int|string|null;
}
