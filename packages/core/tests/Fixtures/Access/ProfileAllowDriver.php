<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\GrantLevel;

class ProfileAllowDriver extends DatabasePermissionDriver
{
    public function isGrantAuthoritative(): bool
    {
        return false;
    }

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return $scope->nodeOwner?->id === 2 ? Decision::deny(GrantLevel::Owner) : Decision::allow(GrantLevel::Owner);
    }
}
