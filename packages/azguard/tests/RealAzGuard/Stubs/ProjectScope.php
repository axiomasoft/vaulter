<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuard\Stubs;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * The `project` assignment scope: the drive owner a Vaulter AccessScope names
 * (`OwnerRef('project', $id)`) is the AzGuard scope the workspace lane checks in.
 */
final class ProjectScope implements AssignmentScopeDefinition
{
    public function type(): string
    {
        return 'project';
    }

    public function model(): ?string
    {
        return WorkspaceModel::class;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        if ($ref->type() !== 'project') {
            return null;
        }

        return new ResolvedAssignmentScope($ref, TenantRef::global(), WorkspaceModel::query()->find($ref->id()));
    }
}
