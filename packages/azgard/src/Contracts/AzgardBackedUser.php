<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Contracts;

use AzGuard\Contracts\HasPermissions;
use AzGuard\Contracts\HasScopedRoles;
use Vaulter\Azgard\AzgardGuardAdapter;

/**
 * The host user the {@see AzgardGuardAdapter} bridges into
 * Vaulter — composed straight from AzGuard's OWN public contracts (0.2+), so there is
 * no re-declared signature to drift out of sync: `HasPermissions` supplies
 * `hasPermission` / `hasPermissionIn` / `isSuperAdmin`, `HasScopedRoles` supplies
 * `hasScopedPermission`.
 *
 * A host User satisfies it with NO extra method bodies — the AzGuard traits already
 * implement every method:
 *
 *   use AzGuard\Concerns\HasAzGuard;       // hasPermission / hasPermissionIn / isSuperAdmin
 *   use AzGuard\Concerns\HasScopedRoles;   // hasScopedPermission
 *   use Vaulter\Azgard\Contracts\AzgardBackedUser;
 *
 *   class User extends Authenticatable implements AzgardBackedUser
 *   {
 *       use HasAzGuard;
 *       use HasScopedRoles;
 *   }
 *
 * It composes the two capability contracts rather than AzGuard's umbrella `AzGuardUser`
 * on purpose: the adapter needs only the permission + scoped-role surface, not the
 * role-relation methods (`roles()`/`scopes()`), so a host that does not use scoped-role
 * relations still integrates.
 */
interface AzgardBackedUser extends HasPermissions, HasScopedRoles {}
