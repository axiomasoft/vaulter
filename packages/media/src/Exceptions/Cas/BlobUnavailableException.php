<?php

declare(strict_types=1);

namespace Vaulter\Media\Exceptions\Cas;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Errors\MediaErrorCode;
use Vaulter\Support\Identity;

/**
 * A pin was refused because the blob's bytes are gone: the blob is being deleted or was deleted by the
 * garbage collector, or it is tombstoned and its object is missing. A caller that still holds the bytes commits
 * them again and pins the returned blob; a caller that only references the blob cannot recover it. The answer
 * depends on a concurrent sweep, so it is never cached as an idempotency receipt.
 */
#[Api]
final class BlobUnavailableException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'blob_unavailable';

    private const int HTTP_STATUS = 409;

    public static function forBlob(int|string $blobId, BlobStatus $status): self
    {
        return new self(
            '[Vaulter] The bytes of blob ['.Identity::stringify($blobId).'] are unavailable (status '.$status->value.'). '.
            'Fix: commit the bytes again through BlobStore::commitStaged() and pin the returned blob.',
        );
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: MediaErrorCode::BlobUnavailable,
            category: ErrorCategory::Conflict,
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
