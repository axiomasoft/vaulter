<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Drive;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** An archived drive is read-only (PLAN2.D37). */
final class DriveArchivedException extends VaulterException implements ProblemException
{
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
}
