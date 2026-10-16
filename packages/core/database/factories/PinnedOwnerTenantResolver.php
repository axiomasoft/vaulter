<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;

/**
 * A pinned tenant that still lets the replaced resolver say which tenant an owner is in.
 */
#[Internal]
final readonly class PinnedOwnerTenantResolver extends PinnedTenantResolver implements ResolvesOwnerTenant
{
    public function __construct(int|string|null $tenant, private ResolvesOwnerTenant $replaced)
    {
        parent::__construct($tenant);
    }

    public function tenantOf(Model $owner): int|string|null
    {
        return $this->replaced->tenantOf($owner);
    }
}
