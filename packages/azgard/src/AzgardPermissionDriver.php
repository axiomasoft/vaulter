<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;

/**
 * The AzGuard host-role driver (ADR-0007, ADR-0025). It bridges a
 * host running AzGuard's per-resource, scoped permission model into Vaulter's
 * composite ACL — WITHOUT vaulter-core depending on azguard/corex. Every AzGuard
 * touch goes through the narrow Vaulter-owned {@see AzgardGuard} seam, so PHPStan
 * stays green with the package absent and the driver references NO concrete
 * AzGuard\* / CoreX\* class.
 *
 * THE COMPOSITE RESOLUTION ORDER (master plan §6 — node-ACL → workspace-scoped role
 * → global), and which lane this driver owns:
 *   1. node-ACL (v_node_grants) — owned by the Vaulter AccessResolver, NOT here;
 *   2. workspace-scoped role — THIS driver's lane: resolve() maps the requested
 *      ability → an AzGuard permission and asks hasPermissionIn(workspaceType,
 *      workspaceId, permission). AccessScope is node-blind (it carries no Node), so the
 *      role lane is the WORKSPACE-scoped check; AzGuard's own per-resource
 *      hasScopedPermission lane is reachable through the same seam for a host adapter
 *      that has the node entity, but the contract hands the driver only the workspace
 *      coordinates (H.2 / ADR-0007 node-blind boundary);
 *   3. global — folded inside AzGuard (its SuperAdmin wildcard surfaces as the
 *      override below, and a globally-granted permission satisfies hasPermissionIn).
 * The driver only ever RAISES access (a role or null); its roles are unioned with the
 * node grants by the AccessResolver.
 *
 *   - override(actor, scope) = isSuperAdmin → Effect::Allow, else null (never a block);
 *   - resolve(subject, actor, scope) = per role, the role's defining Ability is mapped
 *     to a permission and checked via hasPermissionIn against the workspace; the
 *     roles that pass are unioned into the Decision;
 *   - subjectsFor(actor) = [Subject(actor)] only — AzGuard's per-resource model ANSWERS
 *     checks, it does not enumerate the role-subjects a v_node_grants row could be
 *     addressed to, so the driver contributes just the actor (documented limitation:
 *     a host that grants v_node_grants rows to AzGuard roles should additionally model
 *     those as Subjects, e.g. via a subclass; the guard answers the scoped checks);
 *   - canCreateIn = hasPermissionIn(type, id, the create permission).
 *
 * The actor's AzgardGuard is obtained leniently: if the actor itself implements the
 * seam (the host's User uses an AzGuard-backed adapter directly — the cleaner path,
 * since the AzGuard methods naturally live on the user), it is used as-is; otherwise
 * a host-bound AzgardGuard is resolved from the container (the adapter path, e.g. a
 * Corex TenantContextGuard-backed binding). When neither is available the lane is a
 * safe pass-through (null / false), never a fatal — so selecting 'azgard' without a
 * real binding degrades gracefully, exactly like the optional spatie lane.
 *
 * REAL-ECOSYSTEM INTEGRATION IS A HOST STEP: this slice proves the driver logic
 * against the {@see AzgardGuard} seam (a FakeAzgardGuard in tests). A production host
 * must bind a real AzgardGuard adapter delegating to AzGuard's hasScopedPermission /
 * Corex ContextGuard hasPermissionIn, AND align ulid morphs
 * (vaulter.ids.default=ulid + corex.ids.strategy=Ulid + AZ_GUARD_MORPH_TYPE=ulid) and
 * register workspace/owner types as Corex morph aliases.
 */
final class AzgardPermissionDriver implements NonEnumeratingPermissionDriver, PermissionDriver
{
    public function __construct(
        private readonly Container $container,
        private readonly AzgardPermissionMap $permissions,
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
        return Decision::union($this->rolesGranted($guard, $scope->driveOwner->type, $scope->driveOwner->id));
    }

    /**
     * The super-admin override: Effect::Allow when AzGuard's SuperAdmin wildcard is
     * held, else null (never a block).
     */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        $guard = $this->guardFor($actor);

        if ($guard !== null && $guard->isSuperAdmin()) {
            return Effect::Allow;
        }

        return null;
    }

    /**
     * AzGuard answers per-resource checks rather than enumerating the role-subjects a
     * grant row could target, so the subject-set is the actor itself (documented
     * limitation, master plan §6). A host that addresses v_node_grants rows to AzGuard
     * roles should subclass and append those Subjects; the guard still answers the
     * scoped checks for the actor lane.
     *
     * Because this lane does NOT implement
     * {@see EnumeratesRoleSubjects}, a v_node_grants row
     * addressed to a role/team is inert under it; writing one fails loud
     * (GrantNodeAccessAction warns, and throws outside production — F-P1.2-01).
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
     * the folder/upload create path has no existing node to resolve against (§6).
     */
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        $guard = $this->guardFor($actor);

        if ($guard === null) {
            return false;
        }

        return $guard->hasPermissionIn(
            contextType: $scope->driveOwner->type,
            contextId: $scope->driveOwner->id,
            permission: $this->permissions->createPermission(),
        );
    }

    /**
     * The roles the workspace-scoped guard grants: each role is probed by the mapped
     * permission of the ability that defines it.
     *
     * @return list<Role>
     */
    private function rolesGranted(AzgardGuard $guard, string $workspaceType, int|string $workspaceId): array
    {
        $granted = [];
        foreach ($this->roleProbes() as $role => $ability) {
            $permission = $this->permissions->permissionFor($ability);
            if ($permission !== null && $guard->hasPermissionIn(contextType: $workspaceType, contextId: $workspaceId, permission: $permission)) {
                $granted[] = $this->roles->latest($role);
            }
        }

        return $granted;
    }

    /**
     * The role → defining-ability probe map: the ability each role adds over the
     * narrower one. previewer by View, reader by Download, commenter by Comment,
     * writer by Edit, organizer by Share, owner by Manage.
     *
     * @return array<string, Ability>
     */
    private function roleProbes(): array
    {
        return [
            RoleRegistry::PREVIEWER => Ability::View,
            RoleRegistry::READER => Ability::Download,
            RoleRegistry::COMMENTER => Ability::Comment,
            RoleRegistry::WRITER => Ability::Edit,
            RoleRegistry::ORGANIZER => Ability::Share,
            RoleRegistry::OWNER => Ability::Manage,
        ];
    }

    /**
     * Resolve the actor's AzgardGuard: the actor itself when it implements the seam
     * (the cleaner duck-typed path), else a host-bound AzgardGuard from the container,
     * else null (the lane is then a safe pass-through, never a fatal). The container
     * binding lets a host wire an adapter that closes over the resolved actor (e.g. a
     * Corex ContextGuard-backed adapter).
     */
    private function guardFor(Authorizable $actor): ?AzgardGuard
    {
        if ($actor instanceof AzgardGuard) {
            return $actor;
        }

        if (!$this->container->bound(AzgardGuard::class)) {
            return null;
        }

        return $this->container->make(AzgardGuard::class);
    }

    private function isActorSubject(Subject $subject, Authorizable $actor): bool
    {
        if (!$actor instanceof Model) {
            return false;
        }

        return $subject->equals(Subject::ofModel($actor));
    }
}
