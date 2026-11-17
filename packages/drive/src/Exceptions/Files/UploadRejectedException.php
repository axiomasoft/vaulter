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
final class UploadRejectedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(public readonly string $reason)
    {
        parent::__construct('[Vaulter] Upload rejected: '.$reason.'.');
    }

    public function problemCode(): string
    {
        return 'upload_rejected';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function problemDetails(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::UploadRejected,
            category: ErrorCategory::Validation,
            details: ['reason' => $this->reason],
        );
    }
}
