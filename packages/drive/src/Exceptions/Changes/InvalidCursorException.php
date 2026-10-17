<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Changes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** A change-journal cursor that Vaulter did not issue for this drive. */
#[Api]
final class InvalidCursorException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function malformed(): self
    {
        return new self(
            '[Vaulter] Invalid change cursor. '
            .'Why: the cursor was altered, truncated or signed with another application key. '
            .'Fix: start again from DriveHandle::changes()->startCursor() after a full listing. '
            .'Docs: '.self::docs('invalid-change-cursor'),
        );
    }

    public static function otherDrive(): self
    {
        return new self(
            '[Vaulter] The change cursor belongs to another drive or tenant. '
            .'Why: a cursor is valid only for the drive that issued it. '
            .'Fix: use the cursor of this drive, or start from its startCursor(). '
            .'Docs: '.self::docs('invalid-change-cursor'),
        );
    }

    public function problemCode(): string
    {
        return 'invalid_cursor';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
