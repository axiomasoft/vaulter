<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Vaulter\Attributes\Internal;
use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * The deny-all {@see AzgardGuard} the provider binds when the current request has no
 * AzGuard-backed user (an unauthenticated request, or a User that does not implement
 * {@see AzgardBackedUser}). Every probe returns
 * false, so the {@see AzgardPermissionDriver} raises NO
 * access — a SAFE pass-through, never a fatal, exactly as when no guard is bound at all.
 */
#[Internal]
final class NullAzgardGuard implements AzgardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool
    {
        return false;
    }

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
    {
        return false;
    }
}
