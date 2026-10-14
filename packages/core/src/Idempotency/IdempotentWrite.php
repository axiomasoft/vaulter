<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

/**
 * What makes a write idempotent by key (D25, ADR-0029): the codec of its result, the replay authorizer of its
 * owner — a codec is never accepted without one — and the fingerprint of the request: the resource ids, the
 * input fields, the semantic options and the preconditions, never a correlation id or a clock. Passed to
 * `OperationRunner::write()`; a key given to a write without it is refused before the transaction.
 */
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
