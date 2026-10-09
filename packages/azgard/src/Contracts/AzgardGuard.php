<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Contracts;

/**
 * The NARROW Vaulter-owned seam the host's AzGuard-backed actor satisfies (master
 * plan §6 / H.2 / ADR-0007). It exposes EXACTLY the three checks the
 * {@see AzgardPermissionDriver} needs — and nothing of AzGuard's own API — so
 * vaulter-core carries NO hard dependency on azguard/corex and PHPStan stays green
 * with the package ABSENT (no concrete AzGuard\* / CoreX\* references anywhere in
 * the driver path).
 *
 * The host binds a real adapter (the shipped {@see AzgardGuardAdapter} over AzGuard v1's
 * `AzGuardSubject`) for the resolved actor; tests bind {@see FakeAzgardGuard}.
 * Either way the driver only ever sees this interface, never AzGuard's classes.
 *
 * Method shapes map onto AzGuard v1 so a host adapter is a thin pass-through:
 *   - hasScopedPermission($perm, $entity) ≙ AzGuardSubject::hasPermission($perm, on: $entity)
 *   - hasPermissionIn($type, $id, $perm)  ≙ AzGuardSubject::hasPermission($perm, on: AssignmentScopeRef::of($type, $id))
 *   - isSuperAdmin()                      ≙ AzGuardSubject::isSuperAdmin() in the selected panel
 *
 * Morph-alias note (host responsibility): the $contextType is an AzGuard type alias
 * (`[a-z0-9][a-z0-9_.-]*`) — the morph alias of the workspace model, declared as an
 * assignment scope type of the panel. The driver passes the workspace type string
 * through untouched — it never resolves the alias itself.
 */
interface AzgardGuard
{
    /**
     * Does the actor hold $permission scoped to the given resource $entity (the
     * per-node lane)? Mirrors AzGuard's resource check: the super-admin wins first, then
     * a grant in the scope the panel places that exact entity in. $entity is the
     * host's morph-aligned model (e.g. the Node or its owning host record).
     */
    public function hasScopedPermission(string $permission, object $entity): bool;

    /**
     * Does the actor hold $permission within the host workspace identified by its
     * morph ($contextType, $contextId)? Mirrors AzGuard's check in an assignment scope
     * — the workspace-scoped-role lane and the create gate both route through here
     * (there is no Node to resolve against for create).
     */
    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    /**
     * Is the actor a super-admin? Mirrors AzGuard's super-admin role of the panel.
     * When true the resolver short-circuits to an absolute allow override (H.2 step 1),
     * before any ACL or workspace walk.
     */
    public function isSuperAdmin(): bool;
}
