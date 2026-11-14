<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuardIsolated\Stubs;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\AzGuardUser;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\DocumentsPermission;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\ProjectScope;

/**
 * The host panel `app` with ISOLATED project scopes (a tenant-wide grant does not hold
 * inside a project) and a lock restriction that binds super-admins too — the AzGuard
 * rules a bridge must not bypass.
 */
final class IsolatedDocumentsPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'app';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(AzGuardUser::class, guard: 'web')
            ->permissions([DocumentsPermission::class, DatabaseSource::make()])
            ->roles([SuperAdminRole::class, ProjectAdminRole::class])
            ->scopes(AssignmentScopePolicy::isolated(new ProjectScope))
            ->restrictions([new LockedAccountRestriction]);
    }
}
