<?php

declare(strict_types=1);

namespace Vaulter\Security;

use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Contracts\Security\ProductionInvariant;

/** Strict tenancy without a usable TenantResolver class; the resolver is never called (no request tenant in console). */
#[Internal]
final readonly class TenancyInvariant implements ProductionInvariant
{
    public function __construct(private TenancyOptions $tenancy) {}

    public function id(): string
    {
        return 'core.tenancy';
    }

    public function violation(): ?string
    {
        if (!$this->tenancy->enabled) {
            return null;
        }
        try {
            $this->tenancy->requireResolver();
        } catch (Throwable) {
            return 'vaulter.tenancy.resolver must name a class implementing Vaulter\Contracts\Tenancy\TenantResolver while tenancy is enabled.';
        }

        return null;
    }
}
