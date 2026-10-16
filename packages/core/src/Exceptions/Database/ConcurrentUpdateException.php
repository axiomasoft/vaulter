<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Database;

use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A bounded outermost retry exhausted its attempts on a deadlock or serialization
 * failure. The request is safe to repeat, so the response says so instead of a 500.
 */
#[Api]
final class ConcurrentUpdateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('[Vaulter] The request conflicted with a concurrent update; retry it. Docs: '.self::docs('concurrent-update'), 0, $previous);
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
