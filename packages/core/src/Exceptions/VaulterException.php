<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use RuntimeException;
use Vaulter\Attributes\Api;

/**
 * Base for every Vaulter error — catch this to handle all package failures.
 */
#[Api]
class VaulterException extends RuntimeException
{
    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guides/errors.md#'.$anchor;
    }
}
