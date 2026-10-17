<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\ValueObjects\Subject;

#[Internal]
final class ProfileRoleSubjectsDriver extends DatabasePermissionDriver
{
    public function subjectsFor(Authorizable $actor): array
    {
        return [...parent::subjectsFor($actor), new Subject(type: 'role', id: 7)];
    }
}
