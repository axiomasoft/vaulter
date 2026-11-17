<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\VaulterException;

/** Configuration failures are raised during boot and have no HTTP rendering. */
#[Api]
final class InvalidConfigurationException extends VaulterException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::InvalidConfiguration, category: ErrorCategory::Internal);
    }

    public function problemCode(): string
    {
        return 'invalid_configuration';
    }
}
