<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Http;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Internal]
final class WeakEtagNotAllowedException extends VaulterException implements ProblemException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::WeakEtagNotAllowed, category: ErrorCategory::Validation);
    }

    public function problemCode(): string
    {
        return 'weak_etag_not_allowed';
    }

    public function httpStatus(): int
    {
        return 412;
    }

    public function problemDetails(): array
    {
        return [];
    }
}
