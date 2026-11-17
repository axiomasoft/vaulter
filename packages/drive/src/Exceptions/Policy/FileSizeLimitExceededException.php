<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Policy;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A file is larger than the drive policy's maxFileBytes or the installation-wide
 * upload limit. Checked against the declared size before bytes are written, while
 * they stream and against the actual size at finalization.
 */
#[Api]
final class FileSizeLimitExceededException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function forLimit(int $limit): self
    {
        return new self('[Vaulter] The file is larger than '.$limit.' bytes. '
            .'Why: the drive policy or the installation limits the size of one file. '
            .'Fix: upload a smaller file. Docs: '.self::docs('file-size-limit-exceeded'), $limit);
    }

    public function problemCode(): string
    {
        return 'file_size_limit_exceeded';
    }

    public function httpStatus(): int
    {
        return 413;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['limit' => $this->limit];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::FileSizeLimitExceeded,
            category: ErrorCategory::Validation,
            details: ['limit' => $this->limit],
        );
    }
}
