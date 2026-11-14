<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\AzgardUser;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\WorkspaceModel;
use Vaulter\Azgard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The bridge never widens what AzGuard decides: on an isolated panel with a lock
 * restriction, the Vaulter driver grants exactly what AzGuard allows in the workspace —
 * no tenant-wide super-admin leaking into a project, no restriction bypass.
 */
function isolatedDriver(): AzgardPermissionDriver
{
    HostGuard::bind(new AzgardGuardAdapter);

    return app(AzgardPermissionDriver::class);
}

/** A super-admin role granted inside one project, through AzGuard's change pipeline. */
function grantSuperAdminIn(AzgardUser $user, int $project): void
{
    WorkspaceModel::query()->firstOrCreate(['id' => $project], ['name' => 'Project '.$project]);
    $manager = app(AzGuardManager::class);
    $manager->actingAs('testing', static fn () => $manager->panel('app')->for($user)->grantRole('project-admin', on: AssignmentScopeRef::of('project', $project)));
}

function isolatedProject(int $id): AccessScope
{
    return new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: $id), driveId: 1);
}

it('keeps a tenant-wide super-admin out of an isolated workspace', function (): void {
    $user = AzgardUser::query()->create(['id' => 1, 'name' => 'Ada']);
    $this->actingAsSuperAdmin($user);
    $driver = isolatedDriver();

    // AzGuard: a super-admin tenant-wide, not inside project 7.
    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->isSuperAdmin(on: AssignmentScopeRef::of('project', 7)))->toBeFalse()
        // Vaulter mirrors that: no override, no role in the workspace.
        ->and($driver->override($user, isolatedProject(7)))->toBeNull()
        ->and($driver->resolve(Subject::ofModel($user), $user, isolatedProject(7)))->toBeNull();
});

it('grants the owner role to a super-admin of that workspace only', function (): void {
    $user = AzgardUser::query()->create(['id' => 1, 'name' => 'Ada']);
    grantSuperAdminIn($user, 7);
    $driver = isolatedDriver();

    expect($driver->resolve(Subject::ofModel($user), $user, isolatedProject(7))?->role->key)->toBe(RoleRegistry::OWNER)
        ->and($driver->resolve(Subject::ofModel($user), $user, isolatedProject(8)))->toBeNull();
});

it('honours an AzGuard restriction that binds super-admins', function (): void {
    $user = AzgardUser::query()->create(['id' => 1, 'name' => 'Ada']);
    grantSuperAdminIn($user, 7);
    $user->forceFill(['locked_at' => now()])->save();
    $driver = isolatedDriver();

    expect($driver->resolve(Subject::ofModel($user), $user, isolatedProject(7)))->toBeNull()
        ->and($driver->canCreateIn($user, isolatedProject(7)))->toBeFalse();
});
