<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Access\Effect;

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
