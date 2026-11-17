<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class InvalidDriveStateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function for(int|string $driveId, string $operation, DriveStatus|string $status): self
    {
        $status = $status instanceof DriveStatus ? $status->value : $status;

        return new self(
            "[Vaulter] Drive [{$driveId}] cannot {$operation} from status [{$status}]. "
            .'Why: the drive lifecycle only allows active to archived and back, and either to deleting. '
            .'Fix: check the drive status before the call. '
            .'Docs: '.self::docs('invalid-drive-state'),
        );
    }

    public function problemCode(): string
    {
        return 'invalid_drive_state';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::InvalidDriveState,
            category: ErrorCategory::Conflict,
        );
    }
}
