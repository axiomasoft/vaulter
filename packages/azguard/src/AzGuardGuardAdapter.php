<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\AzGuard\Contracts\AzGuardBackedUser;
use Vaulter\AzGuard\Contracts\AzGuardGuard;

/**
 * The REAL {@see AzGuardGuard} adapter (docs/adr/0007-pluggable-authorization.md): it
 * satisfies Vaulter's narrow permission seam by delegating to the actor supplied to each check
 * when it is an AzGuard v1 subject ({@see AzGuardBackedUser}). It captures no user, so the same
 * configured adapter safely serves different actors. This is the production counterpart to
 * {@see Testing\FakeAzGuardGuard} — the {@see AzGuardPermissionDriver} only ever sees the
 * Vaulter seam, so core stays free of any AzGuard dependency; this package carries the
 * concrete bridge.
 *
 * The two translations onto AzGuard v1's `AzGuardSubject`, both AzGuard decisions:
 *   - hasScopedPermission — `hasPermission($permission, on: $entity)`: the entity is an
 *     AzGuard resource (or, when its model is an assignment scope type of the panel,
 *     that scope). The seam's `object $entity` is narrowed to an Eloquent `Model`
 *     (the adapter's boundary job); a non-Model entity yields false;
 *   - hasPermissionIn — `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`:
 *     the workspace is an AzGuard assignment scope (v1's successor of the 0.x context).
 *     A valid type alias the panel does not declare is denied by AzGuard (fail-closed);
 *     a type that is not an AzGuard alias (e.g. a class-name morph) is a host
 *     misconfiguration and AzGuard throws `InvalidAssignmentScopeException`.
 *
 * AzGuard's super-admin needs no translation: its decisions allow a super-admin every
 * grant-based permission, within the panel's scope policy and restrictions.
 *
 * $panelId is AzGuard's `guard:` panel selector: null lets AzGuard select the panel
 * itself — by a full name (`app:documents.view`) or a panel prefix in the permission,
 * else the panel of the request, else the panel of the user model (AzGuard throws when
 * none applies). Vaulter's default `documents.*` map names local keys of that panel.
 */
#[Internal]
final readonly class AzGuardGuardAdapter implements AzGuardGuard
{
    public function __construct(
        private ?string $panelId = null,
    ) {}

    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool
    {
        return $actor instanceof AzGuardBackedUser && $entity instanceof Model
            && $actor->hasPermission($permission, on: $entity, guard: $this->panelId);
    }

    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool
    {
        return $actor instanceof AzGuardBackedUser && $actor->hasPermission(
            $permission,
            on: AssignmentScopeRef::of($contextType, $contextId),
            guard: $this->panelId,
        );
    }
}
