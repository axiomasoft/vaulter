<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Internal;
use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * An explicit deny-all {@see AzgardGuard}. Every actor's probe returns
 * false, so the {@see AzgardPermissionDriver} raises NO
 * access — a SAFE pass-through, never a fatal, exactly as when no guard is bound at all.
 */
#[Internal]
final class NullAzgardGuard implements AzgardGuard
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
