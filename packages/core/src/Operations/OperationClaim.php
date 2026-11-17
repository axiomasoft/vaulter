<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Idempotency\CachedRefusal;
use Vaulter\Idempotency\IdempotencyClaim;
use Vaulter\Idempotency\IdempotentWrite;

/**
 * Stage 4 of the operation pipeline: the idempotency claim. `admit()` runs before the root
 * transaction and refuses what the claim cannot serve; `around()` runs inside the root transaction
 * and either runs the attempt (recording its receipt) or answers from a committed receipt. A refusal
 * whose receipt was recorded comes back as a {@see CachedRefusal}, thrown by the runner after commit.
 *
 * filled by {@see IdempotencyClaim}
 */
#[Internal]
interface OperationClaim
{
    public function admit(Operation $operation, ?IdempotentWrite $idempotency): void;

    /**
     * @template T
     *
     * @param  Closure(): T    $attempt
     * @return T|CachedRefusal
     */
    public function around(Operation $operation, ?IdempotentWrite $idempotency, Closure $attempt): mixed;
}
