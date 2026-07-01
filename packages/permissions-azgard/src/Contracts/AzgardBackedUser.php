<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard\Contracts;

use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Permissions\Azgard\AzgardGuardAdapter;

/**
 * The AzGuard capabilities the {@see AzgardGuardAdapter}
 * calls on the host's authenticated user. The signatures mirror AzGuard 1:1, so a host
 * User that already `use`s AzGuard's `HasAzGuard` (permissions + roles) and
 * `HasScopedRoles` traits SATISFIES this interface with NO extra method bodies — the
 * host only adds `implements AzgardBackedUser` to its User model.
 *
 * WHY a separate interface (not the Vaulter seam directly): Vaulter's
 * {@see AzgardGuard} declares
 * `hasScopedPermission(string, object)`, whereas AzGuard's method narrows the second
 * argument to `Model` — an LSP-incompatible override, so a User cannot implement the
 * Vaulter seam by exposing AzGuard's own methods. The adapter bridges the two: it
 * implements the Vaulter seam and delegates here, narrowing `object` → `Model`.
 */
interface AzgardBackedUser
{
    /**
     * Does the user hold $permission scoped to the given $entity? Mirrors
     * AzGuard\Concerns\HasScopedRoles::hasScopedPermission.
     */
    public function hasScopedPermission(string|UnitEnum $permission, Model $entity, ?string $panelId = null): bool;

    /**
     * Does the user hold $permission within the ($contextType, $contextId) workspace?
     * Mirrors AzGuard\Concerns\HasPermissions::hasPermissionIn (resolved through the
     * bound ContextGuard — the azguard-context / Corex tenancy lane).
     */
    public function hasPermissionIn(
        string $contextType,
        int|string $contextId,
        string|UnitEnum $permission,
        ?string $panelId = null,
    ): bool;

    /**
     * Does the user hold $permission globally? Mirrors
     * AzGuard\Concerns\HasPermissions::hasPermission — used to detect the SuperAdmin
     * wildcard ('*') grant.
     */
    public function hasPermission(string|UnitEnum $permission, ?string $panelId = null): bool;
}
