<?php

declare(strict_types=1);

namespace Vaulter\Drivers\Permission;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Enums\Effect;

/**
 * The default, standalone host-role driver (master plan §6 / H.2) — the zero-host-
 * dependency lane. It introduces NO external role system: decisions come purely
 * from the v_node_grants rows that the (Vaulter-owned) AccessResolver reads, so
 * this driver only contributes the actor's own identity to the subject-set and
 * defers everything else.
 *
 *   - subjectsFor(actor) = [Subject(actor)] — just the user (a host that DOES have
 *     role/team membership can subclass and append those subjects);
 *   - override = null  — no super-admin bypass in the pure-DB lane;
 *   - resolve  = null  — no external role grants; the ACL is the sole authority;
 *   - canCreateInWorkspace = true unless a grant says otherwise (the AccessResolver
 *     still applies CREATE-bit grants on the destination).
 *
 * This is the out-of-the-box driver and what makes the Database lane work in a
 * clean Laravel app with no host AuthZ.
 */
class DatabasePermissionResolver implements HostRoleDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        // No external roles — every decision is an ACL row, resolved by the
        // AccessResolver, not invented here.
        return null;
    }

    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        // No super-admin bypass in the standalone lane.
        return null;
    }

    /**
     * The pure-DB subject-set is the actor itself. A host with role/team membership
     * subclasses this and appends Subject(type:'role', id:…) / Subject(type:'team',
     * …) entries; the base lane keeps zero host coupling.
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

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        // The standalone lane has no workspace-level role gate; per-node CREATE
        // grants are enforced by the AccessResolver against the destination node.
        return true;
    }
}
