<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Vaulter\Errors\VaulterErrorCode;

/**
 * Base for every Vaulter error — catch this to handle all package failures.
 */
#[Api]
class VaulterException extends CodedException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: VaulterErrorCode::InternalError,
            category: ErrorCategory::Internal,
        );
    }

    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guides/errors.md#'.$anchor;
    }
}
