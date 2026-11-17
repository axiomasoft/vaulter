<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\VaulterException;

/** Raised before HTTP serving or a guarded long-lived command when production invariants are violated. */
#[Api]
final class InsecureProductionConfiguration extends VaulterException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::InsecureProductionConfiguration, category: ErrorCategory::Internal);
    }

    public function problemCode(): string
    {
        return 'insecure_production_configuration';
    }
}
