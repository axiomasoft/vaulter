<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Model;

interface ResolvesOwnerTenant
{
    public function tenantOf(Model $owner): int|string|null;
}
