<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Support\Identity;

/** Owner everywhere, except an administrative block on the drives listed in $blocked. */
class ProfileAllowDriver extends DatabasePermissionDriver
{
    /** @var list<string> */
    public static array $blocked = [];

    public function isGrantAuthoritative(): bool
    {
        return false;
    }

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return new Decision(role: (new RoleRegistry)->latest(RoleRegistry::OWNER));
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return in_array(Identity::stringify($scope->driveId), self::$blocked, true) ? Effect::Deny : null;
    }
}
