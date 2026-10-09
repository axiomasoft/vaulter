<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Azgard\Contracts\AzgardBackedUser;
use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * The REAL {@see AzgardGuard} adapter (master plan P1 #2 / §6 / H.2 / ADR-0007): it
 * satisfies Vaulter's narrow permission seam by delegating to an AzGuard v1 subject
 * ({@see AzgardBackedUser}). This is the production counterpart to
 * {@see Testing\FakeAzgardGuard} — the {@see AzgardPermissionDriver} only ever sees the
 * Vaulter seam, so core stays free of any AzGuard dependency; this package carries the
 * concrete bridge.
 *
 * The three translations onto AzGuard v1's `AzGuardSubject`:
 *   - hasScopedPermission — `hasPermission($permission, on: $entity)`: the entity is an
 *     AzGuard resource (or, when its model is an assignment scope type of the panel,
 *     that scope). The seam's `object $entity` is narrowed to an Eloquent `Model`
 *     (the adapter's boundary job); a non-Model entity yields false;
 *   - hasPermissionIn — `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`:
 *     the workspace is an AzGuard assignment scope (v1's successor of the 0.x context).
 *     A scope type the panel does not declare is denied by AzGuard (fail-closed);
 *   - isSuperAdmin — AzGuard's own super-admin question in the selected panel, so the
 *     super-admin role convention lives in AzGuard, not here.
 *
 * $panelId is AzGuard's `guard:` panel selector: null lets AzGuard select the panel
 * itself — by a full name (`app:documents.view`) or a panel prefix in the permission,
 * else the panel of the request, else the panel of the user model. Vaulter's default
 * `documents.*` map names local keys of that panel.
 */
final readonly class AzgardGuardAdapter implements AzgardGuard
{
    public function __construct(
        private AzgardBackedUser $user,
        private ?string $panelId = null,
    ) {}

    public function hasScopedPermission(string $permission, object $entity): bool
    {
        return $entity instanceof Model
            && $this->user->hasPermission($permission, on: $entity, guard: $this->panelId);
    }

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
    {
        return $this->user->hasPermission(
            $permission,
            on: AssignmentScopeRef::of($contextType, $contextId),
            guard: $this->panelId,
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->user->isSuperAdmin(guard: $this->panelId);
    }
}
