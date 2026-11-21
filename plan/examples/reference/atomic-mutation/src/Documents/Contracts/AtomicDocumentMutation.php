<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Contracts;

use Closure;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/documents/src/Contracts/AtomicDocumentMutation.php (public, #[Api]).
 *
 * One Vaulter transaction on the Documents connection: T (drive, shared) → N (node FOR UPDATE) →
 * Edit decision on the locked node → D (document FOR UPDATE) → $operation → COMMIT.
 * The callback:
 *  - may run more than once (the transaction is retried after 40001/40P01), so it derives every value from
 *    snapshot() and writes only through connection();
 *  - performs no network call, LLM call, Node call or queue push;
 *  - receives a scoped object that is closed when the callback returns or throws.
 * The return value of the callback reaches the caller only after COMMIT.
 */
interface AtomicDocumentMutation
{
    /**
     * @template TResult
     *
     * @param  Closure(LockedDocumentMutation): TResult $operation
     * @return TResult
     *
     * @throws \VaulterRef\Documents\Exceptions\DocumentMutationException resource_unavailable before the callback
     */
    public function run(DriveContext $context, string $nodeId, Closure $operation): mixed;
}
