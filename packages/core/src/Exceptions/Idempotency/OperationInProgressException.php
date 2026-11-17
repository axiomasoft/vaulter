<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Idempotency;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * Another request with the same idempotency key is still running and did not finish within the bounded
 * wait. Nothing ran; retry after `Retry-After` seconds to get the outcome of the first request.
 */
#[Api]
final class OperationInProgressException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(private readonly int $retryAfter = 1, ?Throwable $previous = null)
    {
        parent::__construct('[Vaulter] A request with the same idempotency key is still in progress; retry it later. Docs: '.self::docs('operation-in-progress'), 0, $previous);
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::OperationInProgress, category: ErrorCategory::Conflict, retryable: true);
    }

    public function problemCode(): string
    {
        return 'operation_in_progress';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function problemHeaders(): array
    {
        return ['Retry-After' => (string) max(1, $this->retryAfter)];
    }
}
