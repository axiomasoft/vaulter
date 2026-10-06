<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\ValueObjects\Subject;

final class ProfileRoleSubjectsDriver extends DatabasePermissionDriver
{
    public function subjectsFor(Authorizable $actor): array
    {
        return [...parent::subjectsFor($actor), new Subject(type: 'role', id: 7)];
    }
}
