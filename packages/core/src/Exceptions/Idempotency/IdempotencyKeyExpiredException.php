<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Idempotency;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Internal]
final class IdempotencyKeyExpiredException extends VaulterException implements ProblemException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::IdempotencyKeyExpired, category: ErrorCategory::Conflict);
    }

    public function problemCode(): string
    {
        return 'idempotency_key_expired';
    }

    public function httpStatus(): int
    {
        return 410;
    }

    public function problemDetails(): array
    {
        return [];
    }
}
