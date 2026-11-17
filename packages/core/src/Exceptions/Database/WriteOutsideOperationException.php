<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Database;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A Vaulter model was written outside an operation: an integration error, never a
 * client one. Go through the DriveHandle operations.
 */
#[Api]
final class WriteOutsideOperationException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(string $target, ?string $caller = null)
    {
        parent::__construct('[Vaulter] '.$target.' was written outside a Vaulter operation'.($caller === null ? '' : ' (issued at '.$caller.')').'. Use the DriveHandle operations (or disable vaulter.database.write_guard knowingly). Docs: '.self::docs('write-outside-operation'));
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::WriteOutsideOperation, category: ErrorCategory::Internal);
    }

    public function problemCode(): string
    {
        return 'write_outside_operation';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
