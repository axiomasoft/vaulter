<?php

declare(strict_types=1);

namespace Vaulter\Tenant;

use Illuminate\Database\Eloquent\Model;
use Tenant\Contracts\TenantRuntime;
use Tenant\Exceptions\ScopeRequired;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Contracts\Tenancy\TenantResolver as Resolver;
use Vaulter\Tenant\Contracts\OwnerMapping;

/** Both Vaulter resolver ports are bound to this same configured instance. */
final readonly class TenantResolver implements Resolver, ResolvesOwnerTenant
{
    public function __construct(private TenantRuntime $runtime, private OwnerMapping $owners, private ScalarProjection $projection) {}

    public function current(): string
    {
        $state = $this->runtime->current();
        if ($state->kind !== 'active' || $state->context === null) {
            throw new ScopeRequired;
        }

        return $this->projection->encode($state->context->identity);
    }

    public function tenantOf(Model $owner): string
    {
        // Deliberately independent of current(): a freshly mapped A owner remains A while B is active.
        return $this->projection->encode($this->owners->identity($owner));
    }
}
