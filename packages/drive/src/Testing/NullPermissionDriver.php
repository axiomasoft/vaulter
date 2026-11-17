<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Drive\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Drive\Enums\Access\Effect;

/**
 * The fail-loud OPEN host-role driver. It grants every
 * actor full access through an absolute Allow override, so a consumer can boot and
 * exercise Vaulter WITHOUT wiring a real role system.
 *
 * It is selectable ONLY outside production: the PermissionDriverManager rejects it
 * (MissingPermissionDriverException) when the app is in production, so it can never
 * silently open a production install. It lives in src/Testing/ (not tests/) so
 * consumers can reference it from their own test suites.
 */
#[Internal]
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
        // grant walk is skipped entirely.
        return Effect::Allow;
    }

    /**
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        return [];
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return true;
    }
}
