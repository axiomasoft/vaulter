<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Vaulter\Contracts\Tenancy\TenantResolver;

final class MutableTenantResolver implements TenantResolver
{
    public int|string|null $tenant = 'A';

    public function current(): int|string|null
    {
        return $this->tenant;
    }
}
