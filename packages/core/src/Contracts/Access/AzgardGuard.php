<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Testing\FakeAzgardGuard;

/**
 * The NARROW Vaulter-owned seam the host's AzGuard-backed actor satisfies (master
 * plan §6 / H.2 / ADR-0007). It exposes EXACTLY the three checks the
 * {@see AzgardPermissionResolver} needs — and nothing of AzGuard's own API — so
 * vaulter-core carries NO hard dependency on azguard/corex and PHPStan stays green
 * with the package ABSENT (no concrete AzGuard\* / CoreX\* references anywhere in
 * the driver path).
 *
 * The host binds a real adapter (delegating to AzGuard's HasAzGuard concern /
 * Corex TenantContextGuard — `hasScopedPermission` / `hasPermissionIn` / the
 * SuperAdmin wildcard) for the resolved actor; tests bind {@see FakeAzgardGuard}.
 * Either way the driver only ever sees this interface, never AzGuard's classes.
 *
 * Method shapes mirror AzGuard 1:1 so a host adapter is a thin pass-through:
 *   - hasScopedPermission($perm, $entity) ≙ AzGuard\Concerns\HasScopedRoles::hasScopedPermission($perm, $entity)
 *   - hasPermissionIn($type, $id, $perm)  ≙ AzGuard\Concerns\HasPermissions::hasPermissionIn($type, $id, $perm)
 *   - isSuperAdmin()                      ≙ the global SuperAdmin wildcard (*) AzGuard grants
 *
 * Morph-alias note (host responsibility): the $contextType / $entity morph types
 * MUST be registered Corex morph aliases (EntityRegistry / ADR-003) aligned with
 * `vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`.
 * The driver passes the workspace type string through untouched — it never resolves
 * the alias itself.
 */
interface AzgardGuard
{
    /**
     * Does the actor hold $permission scoped to the given resource $entity (the
     * per-node lane)? Mirrors AzGuard's `hasScopedPermission`: the global SuperAdmin
     * wildcard wins first, then a scoped role on that exact entity. $entity is the
     * host's morph-aligned model (e.g. the Node or its owning host record).
     */
    public function hasScopedPermission(string $permission, object $entity): bool;

    /**
     * Does the actor hold $permission within the host workspace identified by its
     * morph ($contextType, $contextId)? Mirrors AzGuard's `hasPermissionIn` (the
     * Corex TenantContextGuard contextual check) — the workspace-scoped-role lane
     * and the create gate both route through here (there is no Node to resolve
     * against for create).
     */
    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    /**
     * Is the actor a global super-admin? Mirrors AzGuard's SuperAdmin wildcard (*).
     * When true the resolver short-circuits to an absolute allow override (H.2 step 1),
     * before any ACL or workspace walk.
     */
    public function isSuperAdmin(): bool;
}
