<?php

declare(strict_types=1);

namespace VaulterRef\Drive;

use VaulterRef\Database\Pg;

/**
 * Port of the access decision the atomic path takes UNDER the node lock.
 * Laravel binding: Vaulter\Documents\Operations (OperationGuard::authorize($context, Ability::Edit, $lockedNode,
 * OperationKind::Mutation)) on the Node returned by DocumentWriteLock::acquire(), on the same connection and
 * without a memoized decision from the pre-transaction check.
 */
interface AccessGate
{
    public function allows(Pg $db, DriveContext $context, string $nodeId, Ability $ability): bool;
}
