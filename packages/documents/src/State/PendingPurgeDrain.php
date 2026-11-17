<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\ValueObjects\Operations\ScopeRef;

/**
 * Drain-or-409 for a purge. Drive's trash purge removes a document inside the transaction its own operation
 * opens, where nothing may wait on the transport. This middleware runs before that transaction: for a root
 * `drive.trash.purge` it drains the trashed documents of the drive that carry a marker, and
 * DocumentContentLifecycle::purge() checks the marker again under the purge's locks — still set, the purge answers
 * 409 document_state_pending and removes nothing.
 *
 * The system purges run as this same root operation: the retention command (actor reason `retention`) and
 * the drive purge step (reason `drive-purge`) purge each root as one `drive.trash.purge`, so they are drained here
 * too; the drive purge step removes a drive being deleted, so it drains every marked document of the drive, not only
 * the trashed ones. A purge composed into another operation, or inside a transaction, is not drained and stays 409
 * while the marker is set.
 */
#[Internal]
final readonly class PendingPurgeDrain implements OperationMiddleware
{
    /** The operation of TrashOperations::purge() and of each root of TrashOperations::empty(). */
    public const string PURGE = 'drive.trash.purge';

    public function __construct(private PendingStateGuard $pending) {}

    public function handle(Operation $operation, Closure $next): mixed
    {
        $scope = $operation->context->scope;
        if ($operation->spec->name === self::PURGE && !$operation->isNested() && $scope->kind === ScopeRef::DRIVE) {
            $actor = $operation->context->actor;
            $this->pending->drainTrashed($scope->id, wholeDrive: $actor->isSystem() && $actor->ref()->reason === OperationGuard::PURGE_REASON);
        }

        return $next($operation);
    }
}
