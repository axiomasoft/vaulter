<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Files;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class ContentUnavailableException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The selected content is not available.');
    }

    public function problemCode(): string
    {
        return 'content_unavailable';
    }

    public function httpStatus(): int
    {
        return 423;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::ContentUnavailable,
            category: ErrorCategory::Validation,
        );
    }
}
