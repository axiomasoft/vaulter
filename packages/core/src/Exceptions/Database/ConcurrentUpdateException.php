<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Database;

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
 * A conflicting write requires a fresh outer transaction. Once its retry budget
 * is exhausted, the caller can retry the request.
 */
#[Api]
final class ConcurrentUpdateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('[Vaulter] The request conflicted with a concurrent update; retry it. Docs: '.self::docs('concurrent-update'), 0, $previous);
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::ConcurrentUpdate, category: ErrorCategory::Conflict, retryable: true);
    }

    public function problemCode(): string
    {
        return 'concurrent_update';
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
        return ['Retry-After' => '1'];
    }
}
