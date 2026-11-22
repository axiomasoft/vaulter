<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Closure;
use Packman\Attributes\Api;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;

/** Atomically edit a document and persist the caller's related records on the Vaulter connection. */
#[Api]
interface AtomicDocumentMutation
{
    /**
     * Call at the outermost transaction level. The operation may run again after SQLSTATE 40001
     * or 40P01. Read the snapshot again and use the scoped connection for related writes on every
     * attempt. Perform external effects only after this method returns the committed result.
     *
     * @template TResult
     *
     * @param Closure(LockedDocumentMutation): TResult $operation
     *
     * @throws NodeNotFoundException if the resource is unavailable to the caller
     *
     * @return TResult
     */
    public function run(DriveContext $context, int|string $nodeId, Closure $operation): mixed;
}
