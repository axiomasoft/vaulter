<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;

/**
 * A configurable HostRoleDriver stub for the ACL tests: it can simulate a host
 * role-grant (resolve → a Decision), an absolute super-admin override, and a fixed
 * extra subject-set. The DatabasePermissionResolver returns null/empty for all of
 * these, so this stub is how the final-veto-after-role-grant and override paths are
 * exercised — and the contract test feeds it the SAME inputs as the real driver to
 * prove identical AccessResolver decisions where they should match.
 */
final class RoleStubDriver implements HostRoleDriver
{
    public function __construct(
        private readonly ?GrantLevel $roleLevel = null,
        private readonly ?Effect $overrideEffect = null,
        private readonly bool $includeActor = true,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        if ($this->roleLevel === null) {
            return null;
        }

        return Decision::allow($this->roleLevel);
    }

    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        return $this->overrideEffect;
    }

    /**
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$this->includeActor || !$actor instanceof Model) {
            return [];
        }

        return [Subject::ofModel($actor)];
    }

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return true;
    }
}
