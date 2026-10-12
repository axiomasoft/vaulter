<?php

declare(strict_types=1);

namespace Vaulter\Access\Drivers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\GrantAuthoritativeDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Effect;

/**
 * The default, standalone host-role driver — the zero-host-
 * dependency lane. It introduces NO external role system: decisions come purely
 * from the v_node_grants rows that the (Vaulter-owned) AccessResolver reads, so
 * this driver only contributes the actor's own identity to the subject-set and
 * defers everything else.
 *
 *   - subjectsFor(actor) = [Subject(actor)] — just the user (a host that DOES have
 *     role/team membership can subclass and append those subjects);
 *   - override = null  — no super-admin bypass in the pure-DB lane;
 *   - resolve  = null  — no external role grants; the ACL is the sole authority;
 *   - canCreateIn = true unless a grant says otherwise (the AccessResolver
 *     still applies CREATE-bit grants on the destination).
 *
 * This is the out-of-the-box driver and what makes the Database lane work in a
 * clean Laravel app with no host AuthZ.
 *
 * It is {@see GrantAuthoritativeDriver} and {@see isGrantAuthoritative()} returns true:
 * v_node_grants is its SOLE authority (no roles, no override, no workspace-role gate), so
 * the access-aware listing may safely narrow to the actor's grant-reachable candidate set.
 * A host that SUBCLASSES this to add a role lane / override MUST override
 * isGrantAuthoritative() to return false, or the listing would wrongly hide nodes that
 * lane allows (the interface alone is inherited and cannot be un-declared).
 */
class DatabasePermissionDriver implements GrantAuthoritativeDriver, PermissionDriver
{
    public function isGrantAuthoritative(): bool
    {
        // The standalone lane has no roles/override — v_node_grants is the sole authority.
        return true;
    }

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        // No external roles — every decision is an ACL row, resolved by the
        // AccessResolver, not invented here.
        return null;
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
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

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        // The standalone lane has no workspace-level role gate; per-node CREATE
        // grants are enforced by the AccessResolver against the destination node.
        return true;
    }
}
