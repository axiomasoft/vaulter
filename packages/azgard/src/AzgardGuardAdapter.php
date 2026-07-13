<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Azgard\Contracts\AzgardBackedUser;
use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;

/**
 * The REAL {@see AzgardGuard} adapter (master plan P1 #2 / §6 / H.2 / ADR-0007): it
 * satisfies Vaulter's narrow permission seam by delegating to an AzGuard-backed user
 * ({@see AzgardBackedUser}). This is the production counterpart to core's
 * FakeAzgardGuard — the {@see AzgardPermissionResolver}
 * only ever sees the Vaulter seam, so core stays free of any AzGuard dependency; this
 * package carries the concrete bridge.
 *
 * The three translations:
 *   - hasScopedPermission — narrows the seam's `object $entity` to an Eloquent `Model`
 *     (AzGuard scopes roles to model rows, and keeps that idiomatic type; the narrowing
 *     is the adapter's boundary job); a non-Model entity yields false;
 *   - hasPermissionIn — a straight pass-through to the workspace-context check;
 *   - isSuperAdmin — AzGuard's first-class super-admin check (0.2+), so the wildcard
 *     convention lives in AzGuard, not here.
 *
 * $panelId threads AzGuard's panel context: null lets AzGuard resolve the panel from
 * each permission key's first segment (the default — `documents.view` → the `documents`
 * panel), which matches Vaulter's `documents.*` permission map.
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
            && $this->user->hasScopedPermission($permission, $entity, $this->panelId);
    }

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
    {
        return $this->user->hasPermissionIn($contextType, $contextId, $permission, $this->panelId);
    }

    public function isSuperAdmin(): bool
    {
        return $this->user->isSuperAdmin($this->panelId);
    }
}
