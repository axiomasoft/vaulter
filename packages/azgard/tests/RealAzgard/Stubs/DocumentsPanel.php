<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard\Stubs;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * The host's AzGuard v1 panel `app` that owns Vaulter's `documents.*` permissions: the User is
 * its subject, a project (the drive owner) is an assignment scope that inherits
 * tenant-wide grants, grants are stored, and the super-admin role exists.
 */
final class DocumentsPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'app';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(AzgardUser::class, guard: 'web')
            ->permissions([DocumentsPermission::class, DatabaseSource::make()])
            ->roles([SuperAdminRole::class])
            ->scopes(AssignmentScopePolicy::inherit(new ProjectScope));
    }
}
