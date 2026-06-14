<?php

declare(strict_types=1);

namespace DocVault\Exceptions;

use RuntimeException;

/**
 * Base for every DocVault error — catch this to handle all package failures.
 */
class DocVaultException extends RuntimeException
{
    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axioma-studio/doc-vault/blob/main/docs/guide/errors.md#'.$anchor;
    }
}
