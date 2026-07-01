<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\Role;
use AzGuard\Testing\FakeGrantSource;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Permissions\Azgard\AzgardGuardAdapter;
use Vaulter\Permissions\Azgard\Tests\RealAzgard\Stubs\AzgardUser;
use Vaulter\Permissions\Azgard\Tests\RealAzgard\Stubs\Roles\DocsViewerRole;
use Vaulter\Permissions\Azgard\Tests\RealAzgard\Stubs\WorkspaceModel;

/**
 * END-TO-END against the REAL AzGuard stack (no doubles): a real Eloquent AzGuard user
 * with real roles / scoped roles / grant sources, the real azguard-context ContextGuard,
 * the real AzgardGuardAdapter and the real Vaulter AzgardPermissionResolver. This is the
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

function driver(): AzgardPermissionResolver
{
    return app(AzgardPermissionResolver::class);
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

    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 7);

    // Real chain: Vaulter override → adapter.isSuperAdmin → AzGuard permissionSet->isWildcard.
    expect($user->isSuperAdmin())->toBeTrue()
        ->and(driver()->override($user, $scope))->toBe(Effect::Allow);
});

it('defers when the real user is not a super-admin', function (): void {
    $user = realUser();
    bindRealAdapter($user);

    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 7);

    expect($user->isSuperAdmin())->toBeFalse()
        ->and(driver()->override($user, $scope))->toBeNull();
});

it('folds a real workspace-context permission into the matching Vaulter grant level', function (): void {
    // A real GrantSource grants documents.update; the real azguard-context ContextGuard
    // answers hasPermissionIn, which the Vaulter driver maps to the Writer level.
    $user = realUser();
    registerGrants(fn (FakeGrantSource $f) => $f->grant('app', 'documents.update'));
    bindRealAdapter($user);

    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 1);
    $decision = driver()->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer)
        ->and($decision->isAllow())->toBeTrue();
});

it('gates real workspace creation through the create permission', function (): void {
    $user = realUser();
    registerGrants(fn (FakeGrantSource $f) => $f->grant('app', 'documents.create'));
    bindRealAdapter($user);

    expect(driver()->canCreateInWorkspace($user, 'project', 5))->toBeTrue();
});

it('yields no decision when the real user holds nothing in the workspace', function (): void {
    $user = realUser();
    bindRealAdapter($user);

    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect(driver()->resolve(Subject::ofModel($user), $user, $scope))->toBeNull()
        ->and(driver()->canCreateInWorkspace($user, 'project', 1))->toBeFalse();
});

it('resolves a real DB-backed scoped role through the adapter (entity lane)', function (): void {
    // A real role attached to a real workspace entity — scope-precise, DB-backed.
    $user = realUser();
    $workspace = WorkspaceModel::query()->create(['id' => 1, 'name' => 'Alpha']);
    $other = WorkspaceModel::query()->create(['id' => 2, 'name' => 'Beta']);

    $role = Role::create(['name' => 'docs-viewer', 'class_name' => DocsViewerRole::class, 'level' => 10]);
    $user->assignScopedRole($role, $workspace);

    $adapter = new AzgardGuardAdapter($user);

    expect($adapter->hasScopedPermission('documents.view', $workspace))->toBeTrue()
        // A permission the role does not grant, and the same role on a different entity.
        ->and($adapter->hasScopedPermission('documents.update', $workspace))->toBeFalse()
        ->and($adapter->hasScopedPermission('documents.view', $other))->toBeFalse();
});
