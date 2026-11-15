<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Vaulter\Attributes\Api;

/**
 * What makes a write idempotent by key (docs/adr/0029-idempotency.md): the codec of its result, the replay authorizer of its
 * owner — a codec is never accepted without one — and the fingerprint of the request: the resource ids, the
 * input fields, the semantic options and the preconditions, never a correlation id or a clock. Passed to
 * `OperationRunner::write()`; a key given to a write without it is refused before the transaction.
 */
#[Api]
final readonly class IdempotentWrite
{
    /**
     * @param array<string, mixed> $fingerprint scalars, null and nested arrays of them
     */
    public function __construct(
        public ReceiptCodec $codec,
        public ReplayAuthorizer $authorizer,
        public array $fingerprint,
    ) {}
}
