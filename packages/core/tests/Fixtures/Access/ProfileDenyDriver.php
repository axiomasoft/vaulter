<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\GrantLevel;

final class ProfileDenyDriver extends DatabasePermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return Decision::deny(GrantLevel::Owner);
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return false;
    }
}
