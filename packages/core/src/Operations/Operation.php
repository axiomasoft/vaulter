<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * One run of an operation as the pipeline sees it: the spec, the context and the number of the
 * attempt (a whole attempt is repeated after a serialization failure or a deadlock). Handed to
 * every middleware and to the owner's attempt.
 */
#[Api]
final readonly class Operation
{
    public function __construct(
        public OperationSpec $spec,
        public OperationContext $context,
        public int $attemptNo = 1,
        private bool $nested = false,
    ) {}

    /** Whether this operation runs inside another one's root transaction (a composition). */
    public function isNested(): bool
    {
        return $this->nested;
    }

    /** The runner's next attempt of the same operation. */
    #[Internal]
    public function attempt(int $attemptNo, bool $nested): self
    {
        return new self(spec: $this->spec, context: $this->context, attemptNo: $attemptNo, nested: $nested);
    }
}
