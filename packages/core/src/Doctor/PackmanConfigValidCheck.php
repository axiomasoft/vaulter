<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Override;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Doctor\Checks\ConfigValidCheck;

/**
 * Gives shared diagnostics a product-owned identity while preserving the local doctor CLI.
 */
#[Internal]
final readonly class PackmanConfigValidCheck implements DoctorCheck
{
    public function __construct(private ConfigValidCheck $check) {}

    #[Override]
    public function key(): string
    {
        return 'vaulter.config.valid';
    }

    #[Override]
    public function title(): string
    {
        return 'Vaulter configuration';
    }

    #[Override]
    public function run(DoctorContext $context): array
    {
        return array_map(
            static fn (DoctorFinding $finding): DoctorFinding => new DoctorFinding(
                status: $finding->status,
                severity: $finding->severity,
                code: str_starts_with($finding->code, 'core.config.') ? 'vaulter.'.substr($finding->code, 5) : $finding->code,
                message: $finding->message,
                remediation: $finding->remediation,
                evidence: $finding->evidence,
                slice: $finding->slice,
            ),
            $this->check->run($context),
        );
    }
}
