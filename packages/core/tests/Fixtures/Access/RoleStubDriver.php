<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Effect;

/**
 * A configurable PermissionDriver stub for the ACL tests: it can simulate a host
 * role-grant (resolve → a Decision), an absolute super-admin override, and a fixed
 * extra subject-set. The DatabasePermissionDriver returns null/empty for all of
 * these, so this stub is how the role-union and override paths are
 * exercised — and the contract test feeds it the SAME inputs as the real driver to
 * prove identical AccessResolver decisions where they should match.
 */
final class RoleStubDriver implements PermissionDriver
{
    public function __construct(
        private readonly ?string $role = null,
        private readonly ?Effect $overrideEffect = null,
        private readonly bool $includeActor = true,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        if ($this->role === null) {
            return null;
        }

        return new Decision(role: (new RoleRegistry)->latest($this->role));
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
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

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return true;
    }
}
