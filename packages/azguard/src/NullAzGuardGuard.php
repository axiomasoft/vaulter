<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Internal;
use Vaulter\AzGuard\Contracts\AzGuardGuard;

/**
 * An explicit deny-all {@see AzGuardGuard}. Every actor's probe returns
 * false, so the {@see AzGuardPermissionDriver} raises NO
 * access — a SAFE pass-through, never a fatal, exactly as when no guard is bound at all.
 */
#[Internal]
final class NullAzGuardGuard implements AzGuardGuard
{
    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool
    {
        return false;
    }

    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool
    {
        return false;
    }
}
