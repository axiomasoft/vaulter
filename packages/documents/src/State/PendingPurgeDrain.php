<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Closure;
use Vaulter\Attributes\Internal;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\ValueObjects\Operations\ScopeRef;

/**
 * Drain-or-409 for a purge (C9). Drive's trash purge removes a document inside the transaction its own operation
 * opens, where nothing may wait on the transport. This middleware runs before that transaction: for a root
 * `drive.trash.purge` it drains the trashed documents of the drive that carry a marker, and
 * DocumentContentLifecycle::purge() checks the marker again under the purge's locks — still set, the purge answers
 * 409 document_state_pending and removes nothing.
 *
 * Purges that do not run as a trash operation (the retention command, a drive purge) are not drained here and stay
 * 409 while the marker is set.
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
            $this->pending->drainTrashed($scope->id);
        }

        return $next($operation);
    }
}
