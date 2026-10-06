<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;

/**
 * The host's pluggable, per-profile role lane (master plan H.2 / D6). It bridges the
 * host application's identity + role system into Vaulter WITHOUT leaking Vaulter's
 * own domain: it never receives a Node and never deals in magic-string actions —
 * only an Authorizable actor and an {@see AccessScope} (profile + drive owner/id + tenant coordinates
 * + node owner). The Vaulter-owned, NON-swappable {@see AccessResolver}
 * combines this driver's answers with the v_node_grants ACL.
 *
 * Swap it via configuration key vaulter.access.permission_driver + permission_drivers map); the
 * default is the standalone {@see DatabasePermissionDriver}.
 *
 * Implementations are a SemVer-frozen public API from M2a — M2b's Gate/Spatie/
 * host authorization drivers implement exactly this surface, and a contract test asserts every
 * driver yields identical AccessResolver decisions on a shared fixture.
 */
interface PermissionDriver
{
    /**
     * The host's role decision for an actor within a scope, or null when the host
     * role system has nothing to say (decisions then come purely from the ACL). A
     * returned Decision is folded as roleAllow into `granted = max(aclAllow,
     * roleAllow)` (H.2 step 4) — it can RAISE access but can never override a deny
     * (the final veto, H.2 step 5, still applies).
     */
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    /**
     * An absolute host override (super-admin / Gate::before), or null when none
     * applies (H.2 step 1). Effect::Allow short-circuits to full access; Effect::Deny
     * short-circuits to no access — both in BOTH directions, before any ACL walk.
     */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    /**
     * Expand an actor into the set of Subjects whose grants apply to them (H.2): the
     * user itself plus any host roles/teams it belongs to. Vaulter builds
     * `WHERE (subject_type, subject_id) IN (...)` from this list, so the driver
     * controls the identity fan-out without Vaulter knowing the host's role schema.
     *
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array;

    /**
     * May the actor create nodes in the given drive scope? Used by the folder/
     * upload create path, where there is no existing node to resolve against
     * (`canCreate`). The scope carries drive/owner/profile/tenant identities, never a Node.
     */
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool;
}
