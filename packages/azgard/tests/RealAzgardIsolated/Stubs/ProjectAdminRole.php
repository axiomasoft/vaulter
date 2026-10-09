<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgardIsolated\Stubs;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\ProjectScope;

/**
 * A host super-admin role granted inside a project: a super-admin of that workspace only.
 */
#[Role('project-admin')]
#[SuperAdmin]
final class ProjectAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }

    public function scopes(): array
    {
        return [ProjectScope::class];
    }
}
