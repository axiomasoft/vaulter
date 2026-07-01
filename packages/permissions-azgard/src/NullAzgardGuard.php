<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard;

use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Permissions\Azgard\Contracts\AzgardBackedUser;

/**
 * The deny-all {@see AzgardGuard} the provider binds when the current request has no
 * AzGuard-backed user (an unauthenticated request, or a User that does not implement
 * {@see AzgardBackedUser}). Every probe returns
 * false, so the {@see AzgardPermissionResolver} raises NO
 * access — a SAFE pass-through, never a fatal, exactly as when no guard is bound at all.
 */
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

    public function isSuperAdmin(): bool
    {
        return false;
    }
}
