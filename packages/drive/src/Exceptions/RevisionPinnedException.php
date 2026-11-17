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
use Vaulter\Support\Identity;

/** A pinned (keep_forever) revision is never deleted, by the API or by the pruning. */
#[Api]
final class RevisionPinnedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forRevision(int|string $revisionId): self
    {
        return new self('[Vaulter] Revision ['.Identity::stringify($revisionId).'] is kept forever and cannot be deleted. '
            .'Fix: unpin it with setRevisionRetention(..., keepForever: false) first.');
    }

    public function problemCode(): string
    {
        return 'revision_pinned';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::RevisionPinned,
            category: ErrorCategory::Conflict,
        );
    }
}
