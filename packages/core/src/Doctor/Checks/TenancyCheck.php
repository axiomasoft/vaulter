<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;
use Vaulter\Configuration\TenancyOptions;

#[Internal]
final readonly class TenancyCheck implements DoctorCheck
{
    public function __construct(private TenancyOptions $tenancy) {}

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
        if (!$this->tenancy->enabled) {
            return [DoctorFinding::ok(code: 'core.tenancy.disabled', message: 'Tenancy is not enabled.', evidence: ['enabled' => false])];
        }

        try {
            $resolver = $this->tenancy->requireResolver();
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'core.tenancy.resolver_invalid', message: 'vaulter.tenancy.resolver does not name a TenantResolver class.', remediation: 'name a class implementing Vaulter\Contracts\Tenancy\TenantResolver', evidence: ['enabled' => true, 'exception' => $exception::class])];
        }

        // A console run has no request: the resolver is not called, only its class is checked.
        return [DoctorFinding::ok(code: 'core.tenancy.resolver_ok', message: 'The tenant resolver class resolves; its value is only known inside an HTTP request.', evidence: ['enabled' => true, 'resolver' => $resolver, 'http_context' => false])];
    }
}
