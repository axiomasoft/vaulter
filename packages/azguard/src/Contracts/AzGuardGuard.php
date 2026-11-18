<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * The NARROW Vaulter-owned seam the host's AzGuard-backed actor satisfies (master
 * plan / docs/adr/0007-pluggable-authorization.md). It exposes EXACTLY the two checks the
 * {@see AzGuardPermissionDriver} needs — and nothing of AzGuard's own API — so the
 * driver path references no concrete AzGuard class.
 *
 * Both checks are AzGuard decisions: AzGuard's scope policy, restrictions, policies,
 * tenant rules and its super-admin role all apply inside them, so the bridge adds no
 * authorization of its own. There is deliberately no super-admin question: AzGuard's
 * `isSuperAdmin()` is not a decision, and a super-admin is allowed every grant-based
 * permission by the decisions themselves.
 *
 * Every check receives the decision's actor explicitly. A configured guard may be shared,
 * but must answer for this actor, including a call with no authenticated request user.
 * It must never substitute Auth::user() or mutate the request's authentication state.
 * The shipped adapter translates that actor's AzGuard v1 `AzGuardSubject` decisions;
 * the driver only sees this interface, never AzGuard's classes.
 *
 * Method shapes map onto AzGuard v1 so a host adapter is a thin pass-through:
 *   - hasScopedPermission($actor, $perm, $entity) ≙ $actor->hasPermission($perm, on: $entity)
 *   - hasPermissionIn($actor, $type, $id, $perm) ≙ $actor->hasPermission($perm, on: AssignmentScopeRef::of($type, $id))
 *
 * Morph-alias note (host responsibility): the $contextType is an AzGuard type alias
 * (`[a-z0-9][a-z0-9_.-]*`) — the morph alias of the workspace model, declared as an
 * assignment scope type of the panel. The driver passes the workspace type string
 * through untouched — it never resolves the alias itself.
 */
#[Spi(SpiKind::Implement)]
interface AzGuardGuard
{
    /**
     * Does the actor hold $permission on the given resource $entity (the per-node
     * lane)? Mirrors AzGuard's decision on a resource: a grant in the scope the panel
     * places that exact entity in. $entity is the host's morph-aligned model (e.g. the
     * Node or its owning host record).
     */
    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool;

    /**
     * Does the actor hold $permission within the host workspace identified by its
     * morph ($contextType, $contextId)? Mirrors AzGuard's decision in an assignment
     * scope — the workspace-scoped-role lane and the create gate both route through
     * here (there is no Node to resolve against for create).
     */
    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool;
}
