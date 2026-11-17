<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Sharing;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** A password-protected share link was opened without its (correct) password. */
#[Api]
final class SharePasswordRequiredException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function challenge(): self
    {
        return new self(
            '[Vaulter] This share link is password protected. '
            .'Why: no password, or a wrong one, was sent with the request. '
            .'Fix: send the password in the X-Share-Password header. '
            .'Docs: '.self::docs('share-password-required'),
        );
    }

    public function problemCode(): string
    {
        return 'share_password_required';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::SharePasswordRequired,
            category: ErrorCategory::Authentication,
        );
    }
}
