<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Enums\Access\Effect;

/** An administrative block on every drive of its profile. */
#[Internal]
final class ProfileDenyDriver extends DatabasePermissionDriver
{
    public function override(Authorizable $actor, AccessScope $scope): Effect
    {
        return Effect::Deny;
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return false;
    }
}
