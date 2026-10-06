<?php

declare(strict_types=1);

namespace Vaulter\Testing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Enums\Access\Effect;

/**
 * The fail-loud OPEN host-role driver (master plan H.2 / D6″). It grants every
 * actor full access through an absolute Allow override, so a consumer can boot and
 * exercise Vaulter WITHOUT wiring a real role system.
 *
 * It is selectable ONLY outside production: the PermissionDriverManager rejects it
 * (MissingPermissionDriverException) when the app is in production, so it can never
 * silently open a production install. It lives in src/Testing/ (not tests/) so
 * consumers can reference it from their own test suites.
 */
final class NullPermissionDriver implements NonEnumeratingPermissionDriver, NonProductionPermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        // The open grant is expressed through the absolute override below, so the
        // per-subject role lane has nothing to add.
        return null;
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        // Absolute open: every actor is treated as a super-admin in non-prod, so the
        // ACL/role walk is short-circuited entirely (H.2 step 1).
        return Effect::Allow;
    }

    /**
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        return [];
    }

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return true;
    }
}
