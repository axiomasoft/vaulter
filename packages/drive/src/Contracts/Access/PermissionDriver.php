<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\Effect;

/**
 * The host's pluggable, per-profile role lane. It bridges the
 * host application's identity + role system into Vaulter WITHOUT leaking Vaulter's
 * own domain: it never receives a Node and never deals in magic-string actions —
 * only an Authorizable actor and an {@see AccessScope} (profile + drive owner/id + tenant
 * coordinates). The Vaulter-owned, NON-swappable {@see AccessResolver} unions this
 * driver's roles with the roles of the v_node_grants rows.
 *
 * Swap it via configuration key vaulter-drive.access.permission_driver + permission_drivers map); the
 * default is the standalone {@see DatabasePermissionDriver}.
 *
 * Implementations are a SemVer-frozen public API from — 's Gate/Spatie/
 * host authorization drivers implement exactly this surface, and a contract test asserts every
 * driver yields identical AccessResolver decisions on a shared fixture.
 */
#[Spi(SpiKind::Implement)]
interface PermissionDriver
{
    /**
     * The host's role for a subject within a scope, or null when the host role
     * system has nothing to say (access then comes purely from grants). The role is
     * unioned with every grant that applies — a Decision only ever adds abilities.
     */
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    /**
     * An absolute host override, or null when none applies. Effect::Allow is a
     * super-user bypass (every ability); Effect::Deny is an administrative block
     * (no ability, whatever the grants say). Both apply before any grant walk and
     * are not stopped by inheritance boundaries.
     */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    /**
     * Expand an actor into the set of Subjects whose grants apply to them: the
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
