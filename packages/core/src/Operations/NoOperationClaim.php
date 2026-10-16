<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Idempotency\IdempotentWrite;

/**
 * A pipeline without idempotency (a runner built by hand in a test): a key is refused before the
 * transaction, so it is never silently ignored.
 */
#[Internal]
final readonly class NoOperationClaim implements OperationClaim
{
    public function admit(Operation $operation, ?IdempotentWrite $idempotency): void
    {
        if ($operation->context->idempotencyKey !== null) {
            throw new InvalidArgumentException(sprintf('[Vaulter] The operation "%s" does not accept an idempotency key.', $operation->spec->name));
        }
    }

    public function around(Operation $operation, ?IdempotentWrite $idempotency, Closure $attempt): mixed
    {
        return $attempt();
    }
}
