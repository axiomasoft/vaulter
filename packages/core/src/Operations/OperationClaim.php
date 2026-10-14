<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;

/**
 * Stage 4 of the operation pipeline: the idempotency claim (D25). `admit()` runs before the root
 * transaction and refuses what the claim cannot serve; `around()` runs inside the root transaction
 * and either runs the attempt (recording its receipt) or answers from a committed receipt.
 *
 * @internal the seam the idempotency store fills (P3.12); until then {@see NoOperationClaim}
 */
interface OperationClaim
{
    public function admit(Operation $operation): void;

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    public function around(Operation $operation, Closure $attempt): mixed;
}
