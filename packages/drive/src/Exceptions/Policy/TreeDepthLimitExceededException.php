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

/** The operation would place a node deeper below the root than the drive policy's maxFolderDepth. */
#[Api]
final class TreeDepthLimitExceededException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function forDepth(int $depth, int $limit): self
    {
        return new self('[Vaulter] The operation would nest a node '.$depth.' levels below the root. '
            .'Why: the drive policy allows at most '.$limit.' levels. '
            .'Fix: choose a shallower destination. Docs: '.self::docs('tree-depth-limit-exceeded'), $limit);
    }

    public function problemCode(): string
    {
        return 'tree_depth_limit_exceeded';
    }

    public function httpStatus(): int
    {
        return 409;
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
            code: DriveErrorCode::TreeDepthLimitExceeded,
            category: ErrorCategory::Conflict,
            details: ['limit' => $this->limit],
        );
    }
}
