<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Throwable;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class TenancyCheck implements DoctorCheck
{
    public function __construct(private TenancyConfig $tenancy) {}

    public function key(): string
    {
        return 'core.tenancy';
    }

    public function title(): string
    {
        return 'Tenancy';
    }

    public function run(DoctorContext $context): array
    {
        if (!$this->tenancy->enabled()) {
            return [DoctorFinding::ok(code: 'core.tenancy.disabled', message: 'Tenancy is not enabled.', evidence: ['enabled' => false])];
        }

        try {
            $resolver = $this->tenancy->resolver();
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'core.tenancy.resolver_invalid', message: 'vaulter.tenancy.resolver does not name a TenantResolver class.', hint: 'name a class implementing Vaulter\Contracts\Tenancy\TenantResolver', evidence: ['enabled' => true, 'exception' => $exception::class])];
        }

        // A console run has no request: the resolver is not called, only its class is checked.
        return [DoctorFinding::ok(code: 'core.tenancy.resolver_ok', message: 'The tenant resolver class resolves; its value is only known inside an HTTP request.', evidence: ['enabled' => true, 'resolver' => $resolver, 'http_context' => false])];
    }
}
