<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\Effect;
use Vaulter\Support\Identity;

/** Owner everywhere, except an administrative block on the drives listed in $blocked. */
#[Internal]
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
