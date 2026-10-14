<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Idempotency;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The idempotency key was already used by the same actor for this operation with a different request
 * (D25). Nothing ran; the stored outcome belongs to the first request. Use a fresh key for a new request.
 */
final class IdempotencyKeyReusedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forOperation(string $operation): self
    {
        return new self(sprintf(
            '[Vaulter] The idempotency key was already used for "%s" with a different request. Fix: send a new key for a new request. Docs: %s',
            $operation,
            self::docs('idempotency-key-reused'),
        ));
    }

    public function problemCode(): string
    {
        return 'idempotency_key_reused';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
