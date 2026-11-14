<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuardIsolated\Stubs;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\ProjectScope;

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
