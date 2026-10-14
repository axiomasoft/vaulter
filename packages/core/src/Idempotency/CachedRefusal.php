<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Throwable;

/**
 * A refusal whose receipt was written: the attempt rolled back to its savepoint, the receipt is part of the
 * root transaction, and the runner throws the refusal only after that transaction committed (D25).
 *
 * @internal
 */
final readonly class CachedRefusal
{
    public function __construct(public Throwable $refusal) {}
}
