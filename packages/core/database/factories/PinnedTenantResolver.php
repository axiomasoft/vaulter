<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Contracts\Tenancy\TenantResolver;

/**
 * Resolves one fixed tenant, so a factory or a contract suite can act as a tenant other
 * than the current one.
 *
 * @internal
 */
readonly class PinnedTenantResolver implements TenantResolver
{
    public function __construct(protected int|string|null $tenant) {}

    /**
     * Pin $tenant over $replaced. When the replaced resolver also answers which tenant an
     * owner belongs to, the pinned one keeps asking it, so an owner of another tenant is
     * still refused; otherwise there is no owner check to keep.
     */
    public static function over(int|string|null $tenant, TenantResolver $replaced): TenantResolver
    {
        return $replaced instanceof ResolvesOwnerTenant ? new PinnedOwnerTenantResolver($tenant, $replaced) : new self($tenant);
    }

    public function current(): int|string|null
    {
        return $this->tenant;
    }
}
