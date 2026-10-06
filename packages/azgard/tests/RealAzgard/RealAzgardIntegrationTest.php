<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\Role;
use AzGuard\Testing\FakeGrantSource;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\AzgardUser;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\Roles\DocsViewerRole;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\WorkspaceModel;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * END-TO-END against the REAL AzGuard stack (no doubles): a real Eloquent AzGuard user
 * with real roles / scoped roles / grant sources, the real azguard-context ContextGuard,
 * the real AzgardGuardAdapter and the real Vaulter AzgardPermissionDriver. This is the
 * proof the FakeAzgardGuard unit tests cannot give — that the adapter's calls actually
 * resolve through AzGuard's own engine.
 */
function realUser(int $id = 1): AzgardUser
{
    return AzgardUser::query()->create(['id' => $id, 'name' => 'Ada']);
}

function bindRealAdapter(AzgardUser $user): void
{
    app()->instance(AzgardGuard::class, new AzgardGuardAdapter($user));
}

function driver(): AzgardPermissionDriver
{
    return app(AzgardPermissionDriver::class);
}

/** Register a fake grant source (a REAL GrantSource in AzGuard's pipeline) for this test. */
function registerGrants(callable $configure): void
{
    $fake = new FakeGrantSource;
    $configure($fake);
    app()->instance(FakeGrantSource::class, $fake);
    AzGuard::registerGrantSource(FakeGrantSource::class);
}

it('resolves a real AzGuard super-admin to a Vaulter absolute-allow override', function (): void {
    $user = realUser();
    registerGrants(fn (FakeGrantSource $f) => $f->wildcard());
    bindRealAdapter($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 7), driveId: 1);

    // Real chain: Vaulter override → adapter.isSuperAdmin → AzGuard permissionSet->isWildcard.
    expect($user->isSuperAdmin())->toBeTrue()
        ->and(driver()->override($user, $scope))->toBe(Effect::Allow);
});

it('defers when the real user is not a super-admin', function (): void {
    $user = realUser();
    bindRealAdapter($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 7), driveId: 1);

    expect($user->isSuperAdmin())->toBeFalse()
        ->and(driver()->override($user, $scope))->toBeNull();
});

it('folds a real workspace-context permission into the matching Vaulter grant level', function (): void {
    // A real GrantSource grants documents.update; the real azguard-context ContextGuard
    // answers hasPermissionIn, which the Vaulter driver maps to the Writer level.
    $user = realUser();
    registerGrants(fn (FakeGrantSource $f) => $f->grant('app', 'documents.update'));
    bindRealAdapter($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);
    $decision = driver()->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer)
        ->and($decision->isAllow())->toBeTrue();
});

it('gates real workspace creation through the create permission', function (): void {
    $user = realUser();
    registerGrants(fn (FakeGrantSource $f) => $f->grant('app', 'documents.create'));
    bindRealAdapter($user);

    expect(driver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 5), driveId: 1)))->toBeTrue();
});

it('yields no decision when the real user holds nothing in the workspace', function (): void {
    $user = realUser();
    bindRealAdapter($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect(driver()->resolve(Subject::ofModel($user), $user, $scope))->toBeNull()
        ->and(driver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1)))->toBeFalse();
});

it('resolves a real DB-backed scoped role through the adapter (entity lane)', function (): void {
    // A real role attached to a real workspace entity — scope-precise, DB-backed.
    $user = realUser();
    $workspace = WorkspaceModel::query()->create(['id' => 1, 'name' => 'Alpha']);
    $other = WorkspaceModel::query()->create(['id' => 2, 'name' => 'Beta']);

    $role = new Role;
    $role->forceFill(['name' => 'docs-viewer', 'class_name' => DocsViewerRole::class, 'level' => 10])->save();
    $user->assignScopedRole($role, $workspace);

    $adapter = new AzgardGuardAdapter($user);

    expect($adapter->hasScopedPermission('documents.view', $workspace))->toBeTrue()
        // A permission the role does not grant, and the same role on a different entity.
        ->and($adapter->hasScopedPermission('documents.update', $workspace))->toBeFalse()
        ->and($adapter->hasScopedPermission('documents.view', $other))->toBeFalse();
});
