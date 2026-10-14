<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use InvalidArgumentException;

/**
 * No operation accepts an idempotency key yet: a key is refused before the transaction, so it is
 * never silently ignored.
 *
 * @internal
 */
final readonly class NoOperationClaim implements OperationClaim
{
    public function admit(Operation $operation): void
    {
        if ($operation->context->idempotencyKey !== null) {
            throw new InvalidArgumentException(sprintf('[Vaulter] The operation "%s" does not accept an idempotency key.', $operation->spec->name));
        }
    }

    public function around(Operation $operation, Closure $attempt): mixed
    {
        return $attempt();
    }
}
