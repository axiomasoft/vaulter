<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Drive;

use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

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
}
