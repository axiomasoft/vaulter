<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\AzGuard\Configuration\AzGuardOptions;
use Vaulter\AzGuard\Contracts\AzGuardBackedUser;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\Drive\Access\Roles\Role;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Effect;
use Vaulter\Kernel;

/**
 * The AzGuard host-role driver (docs/adr/0007-pluggable-authorization.md, docs/adr/0025-access-model-v1.md). It bridges a
 * host running AzGuard's per-resource, scoped permission model into Vaulter's
 * composite ACL — WITHOUT vaulter-core depending on azguard. Every AzGuard
 * touch goes through the narrow Vaulter-owned {@see AzGuardGuard} seam, so PHPStan
 * stays green with the package absent and the driver references NO concrete
 * AzGuard\* class.
 *
 * THE COMPOSITE RESOLUTION ORDER (node-ACL → workspace-scoped role
 * → global), and which lane this driver owns:
 *   1. node-ACL (v_node_grants) — owned by the Vaulter AccessResolver, NOT here;
 *   2. workspace-scoped role — THIS driver's lane: resolve() maps each registered role's
 *      abilities → AzGuard permissions and asks hasPermissionIn(actor, workspaceType,
 *      workspaceId, permission). AccessScope is node-blind (it carries no Node), so the
 *      role lane is the WORKSPACE-scoped check; AzGuard's own per-resource
 *      hasScopedPermission lane is reachable through the same seam for a host adapter
 *      that has the node entity, but the contract hands the driver only the workspace
 *      coordinates (docs/adr/0007-pluggable-authorization.md node-blind boundary);
 *   3. global — folded inside AzGuard: a tenant-wide grant satisfies hasPermissionIn in an
 *      inheriting scope, and AzGuard's super-admin is not a separate lane — AzGuard's own
 *      decision allows a super-admin every grant-based permission in the workspace (under
 *      its scope policy, restrictions and tenant rules), so the complete checks yield the
 *      owner role.
 * The driver only ever RAISES access (a role or null); its roles are unioned with the
 * node grants by the AccessResolver.
 *
 *   - override(actor, scope) = null: AzGuard answers only decisions (its `isSuperAdmin()`
 *     is not one), so the bridge never turns AzGuard state into an absolute Vaulter
 *     override that would bypass AzGuard's restrictions, policies or scope isolation;
 *   - resolve(subject, actor, scope) = every ability of a role must have a mapping and be
 *     allowed in the workspace. Only complete roles are unioned into the Decision. An
 *     incomplete bundle grants its narrower complete roles, never unconfirmed abilities;
 *   - subjectsFor(actor) = [Subject(actor)] only — AzGuard's per-resource model ANSWERS
 *     checks, it does not enumerate the role-subjects a v_node_grants row could be
 *     addressed to, so the driver contributes just the actor (documented limitation:
 *     a host that grants v_node_grants rows to AzGuard roles should additionally model
 *     those as Subjects, e.g. via a subclass; the guard answers the scoped checks);
 *   - canCreateIn = hasPermissionIn(actor, type, id, the create permission).
 *
 * The guard is ALWAYS about the decision's `$actor`, never about whoever the request
 * happens to be: the actor itself when it implements the seam; else the guard the HOST
 * configured in `vaulter-azguard.guard` (an explicit choice — the host owns it and must
 * answer for the explicit actor argument); else an {@see AzGuardGuardAdapter} for an
 * {@see AzGuardBackedUser} (with the configured panel). No guard is ever
 * built from the REQUEST's user: an actor differing from the request user (an
 * acting-as/preview check, or the collaboration hub's call that has no request user at all)
 * must neither borrow the request user's rights nor be denied for the lack of them. When none applies the lane is a safe
 * pass-through (null / false), never a fatal — so selecting 'azguard' without a real binding
 * degrades gracefully, exactly like the optional spatie lane.
 *
 * The host owns the AzGuard v1 panel: its User is a subject model of the panel, the
 * workspace (drive owner) morph alias is an assignment scope type of the panel, and the
 * mapped permissions are permissions of the panel.
 */
#[Api]
final class AzGuardPermissionDriver implements NonEnumeratingPermissionDriver, PermissionDriver
{
    #[Internal]
    public function __construct(
        private readonly Kernel $kernel,
        private readonly AzGuardOptions $config,
        private readonly AzGuardPermissionMap $permissions,
        private readonly RoleRegistry $roles,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        $guard = $this->guardFor($actor);

        // The AzGuard check is user-scoped: only evaluate the actor's own Subject so
        // role/team Subjects (which AzGuard does not enumerate) never double-count.
        if ($guard === null || !$this->isActorSubject($subject, $actor)) {
            return null;
        }

        // Node-blind: AccessScope carries the workspace, not a Node, so the role lane is
        // the WORKSPACE-scoped permission check (composite order step 2). A null
        // workspace means there is nothing for AzGuard to scope against.
        return Decision::union($this->rolesGranted($guard, $actor, $scope->driveOwner->type, $scope->driveOwner->id));
    }

    /**
     * No override: every AzGuard answer is a decision in the workspace, and a super-admin
     * reaches the owner role through those decisions in {@see resolve()}.
     */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return null;
    }

    /**
     * AzGuard answers per-resource checks rather than enumerating the role-subjects a
     * grant row could target, so the subject-set is the actor itself (documented
     * limitation, ). A host that addresses v_node_grants rows to AzGuard
     * roles should subclass and append those Subjects; the guard still answers the
     * scoped checks for the actor lane.
     *
     * Because this lane does NOT implement
     * {@see EnumeratesRoleSubjects}, a v_node_grants row
     * addressed to a role/team is inert under it; writing one fails loud
     * (GrantNodeAccessAction warns, and throws outside production).
     *
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$actor instanceof Model) {
            return [];
        }

        return [Subject::ofModel($actor)];
    }

    /**
     * May the actor create nodes in the host workspace? Delegates to AzGuard's
     * workspace-scoped check (hasPermissionIn) with the configured create permission —
     * the folder/upload create path has no existing node to resolve against.
     */
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        $guard = $this->guardFor($actor);

        if ($guard === null) {
            return false;
        }

        return $guard->hasPermissionIn(
            actor: $actor,
            contextType: $scope->driveOwner->type,
            contextId: $scope->driveOwner->id,
            permission: $this->permissions->createPermission(),
        );
    }

    /** @return list<Role> Only complete registered roles whose every mapped ability the actor holds. */
    private function rolesGranted(AzGuardGuard $guard, Authorizable $actor, string $workspaceType, int|string $workspaceId): array
    {
        $granted = [];
        $map = $this->permissions->permissionMap();
        $allowed = [];
        foreach ($this->roles->all() as $role) {
            foreach ($role->abilities as $ability) {
                $permission = $map[$ability->value] ?? null;
                if ($permission === null) {
                    continue 2;
                }
                $allowed[$permission] ??= $guard->hasPermissionIn(actor: $actor, contextType: $workspaceType, contextId: $workspaceId, permission: $permission);
                if (!$allowed[$permission]) {
                    continue 2;
                }
            }
            $granted[] = $role;
        }

        return $granted;
    }

    /**
     * Resolve the guard that answers for `$actor` (see the class docblock): the actor
     * itself, the guard the host configured, or an adapter over a backed actor.
     */
    private function guardFor(Authorizable $actor): ?AzGuardGuard
    {
        if ($actor instanceof AzGuardGuard) {
            return $actor;
        }

        $capabilities = $this->kernel->capabilities();
        if ($capabilities->has(AzGuardGuard::class)) {
            return $capabilities->require(AzGuardGuard::class);
        }

        if ($actor instanceof AzGuardBackedUser) {
            return new AzGuardGuardAdapter(panelId: $this->config->panelId());
        }

        return null;
    }

    private function isActorSubject(Subject $subject, Authorizable $actor): bool
    {
        if (!$actor instanceof Model) {
            return false;
        }

        return $subject->equals(Subject::ofModel($actor));
    }
}
