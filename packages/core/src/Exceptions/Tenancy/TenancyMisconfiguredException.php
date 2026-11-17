<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Tenancy;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\VaulterException;

/** Strict tenancy has no valid resolver or current tenant; all environments fail closed. */
#[Api]
final class TenancyMisconfiguredException extends VaulterException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::TenancyMisconfigured, category: ErrorCategory::Internal);
    }
}
