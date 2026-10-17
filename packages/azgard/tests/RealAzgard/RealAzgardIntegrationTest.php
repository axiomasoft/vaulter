<?php

declare(strict_types=1);

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
 * END-TO-END against the REAL AzGuard v1 stack (no doubles): a real Eloquent AzGuard
 * subject with grants stored through AzGuard's change pipeline (the v1 Testing helpers),
 * assignment scopes and the super-admin role decided by AzGuard, the real AzgardGuardAdapter and the real
 * Vaulter AzgardPermissionDriver. This is the proof the FakeAzgardGuard unit tests cannot
 * give — that the adapter's calls actually resolve through AzGuard's own engine.
 */
function realUser(int $id = 1): AzgardUser
{
    return AzgardUser::query()->create(['id' => $id, 'name' => 'Ada']);
}

function bindRealAdapter(AzgardUser $user): void
{
    HostGuard::bind(new AzgardGuardAdapter($user));
}

function driver(): AzgardPermissionDriver
{
    return app(AzgardPermissionDriver::class);
}

function projectScope(int $id): AccessScope
{
    return new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: $id), driveId: 1);
}

it('resolves a real AzGuard super-admin to the owner role through AzGuard decisions, never an override', function (): void {
    $user = realUser();
    $this->actingAsSuperAdmin($user);
    bindRealAdapter($user);

    // Real chain: driver probes → adapter.hasPermission in project:7 → AzGuard decides
    // every permission for the super-admin role (inherit panel).
    expect(driver()->override($user, projectScope(7)))->toBeNull()
        ->and(driver()->resolve(Subject::ofModel($user), $user, projectScope(7))?->role->key)->toBe(RoleRegistry::OWNER)
        ->and(driver()->canCreateIn($user, projectScope(7)))->toBeTrue();
});

it('folds a real assignment-scope permission into the matching Vaulter role, only in that workspace', function (): void {
    // A grant stored in the project:1 assignment scope; the adapter asks hasPermission
    // on that scope, which the Vaulter driver maps to the writer role.
    $user = realUser();
    $this->actingAsWithPermissions($user, ['documents.update'], on: AssignmentScopeRef::of('project', 1));
    bindRealAdapter($user);

    $decision = driver()->resolve(Subject::ofModel($user), $user, projectScope(1));

    expect($decision)->not->toBeNull()
        ->and($decision?->role->key)->toBe(RoleRegistry::WRITER)
        // Negative control: the same grant does not leak into another workspace.
        ->and(driver()->resolve(Subject::ofModel($user), $user, projectScope(2)))->toBeNull();
});

it('lets a tenant-wide grant hold in every inheriting workspace', function (): void {
    $user = realUser();
    $this->actingAsWithPermissions($user, ['documents.view']);
    bindRealAdapter($user);

    $decision = driver()->resolve(Subject::ofModel($user), $user, projectScope(3));

    expect($decision?->role->key)->toBe(RoleRegistry::PREVIEWER);
});

it('gates real workspace creation through the create permission', function (): void {
    $user = realUser();
    $this->actingAsWithPermissions($user, ['documents.create'], on: AssignmentScopeRef::of('project', 5));
    bindRealAdapter($user);

    expect(driver()->canCreateIn($user, projectScope(5)))->toBeTrue()
        ->and(driver()->canCreateIn($user, projectScope(6)))->toBeFalse();
});

it('denies a workspace whose type is not an assignment scope type of the panel (fail-closed)', function (): void {
    $user = realUser();
    $this->actingAsWithPermissions($user, ['documents.update']);

    expect((new AzgardGuardAdapter($user))->hasPermissionIn('team', 1, 'documents.update'))->toBeFalse();
});

it('yields no decision when the real user holds nothing in the workspace', function (): void {
    $user = realUser();
    bindRealAdapter($user);

    expect(driver()->resolve(Subject::ofModel($user), $user, projectScope(1)))->toBeNull()
        ->and(driver()->canCreateIn($user, projectScope(1)))->toBeFalse();
});

it('resolves a real entity check through the adapter (entity lane)', function (): void {
    // The workspace model is an assignment scope type of the panel, so the entity is
    // that scope: scope-precise and stored.
    $user = realUser();
    $workspace = WorkspaceModel::query()->create(['id' => 1, 'name' => 'Alpha']);
    $other = WorkspaceModel::query()->create(['id' => 2, 'name' => 'Beta']);
    $this->actingAsWithPermissions($user, ['documents.view'], on: $workspace);

    $adapter = new AzgardGuardAdapter($user);

    expect($adapter->hasScopedPermission('documents.view', $workspace))->toBeTrue()
        // A permission not granted, and the same grant on a different entity.
        ->and($adapter->hasScopedPermission('documents.update', $workspace))->toBeFalse()
        ->and($adapter->hasScopedPermission('documents.view', $other))->toBeFalse();
});
