<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** An archived drive is read-only. */
#[Api]
final class DriveArchivedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forDrive(int|string $driveId): self
    {
        return new self(
            "[Vaulter] Drive [{$driveId}] is archived. "
            .'Why: an archived drive accepts reads only. '
            .'Fix: unarchive the drive before changing it. '
            .'Docs: '.self::docs('drive-archived'),
        );
    }

    public function problemCode(): string
    {
        return 'drive_archived';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::DriveArchived,
            category: ErrorCategory::Conflict,
        );
    }
}
