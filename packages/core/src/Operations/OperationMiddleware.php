<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;

/**
 * A slot contribution that wraps every write operation (D8). It calls `$next` exactly once with the
 * operation it was given and returns exactly what `$next` returned; anything else fails the
 * operation with `extension_failed`. Throwing before `$next` vetoes the operation without a mutation.
 * It never replaces the transaction, the authorization or the events of the operation.
 */
interface OperationMiddleware
{
    /** @param Closure(Operation): mixed $next */
    public function handle(Operation $operation, Closure $next): mixed;
}
