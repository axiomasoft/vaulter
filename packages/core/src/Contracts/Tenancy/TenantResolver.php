<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Tenancy;

interface TenantResolver
{
    public function current(): int|string|null;
}
