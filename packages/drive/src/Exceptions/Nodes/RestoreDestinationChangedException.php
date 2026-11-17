<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class RestoreDestinationChangedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The restore destination changed; retry with current permissions.');
    }

    public function problemCode(): string
    {
        return 'restore_destination_changed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::RestoreDestinationChanged,
            category: ErrorCategory::Conflict,
        );
    }
}
