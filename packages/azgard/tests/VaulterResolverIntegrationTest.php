<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Tests\Stubs\SpyAzgardUser;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The bridge driving the REAL Vaulter AzgardPermissionDriver: the adapter is bound as
 * the host's AzgardGuard, and the driver's every lane (the workspace-role → role fold,
 * a super-admin through the same decisions, the create gate) is exercised end-to-end. Only the
 * leaf user is a controllable double; the adapter and the resolver are production code.
 */
function bindAdapterFor(SpyAzgardUser $user): void
{
    app()->instance(AzgardGuard::class, new AzgardGuardAdapter($user));
}

function azgardDriver(): AzgardPermissionDriver
{
    return app(AzgardPermissionDriver::class);
}

it('folds an AzGuard super-admin into the owner role through the workspace decisions, never an override', function (): void {
    $user = SpyAzgardUser::make()->makeSuperAdmin();
    bindAdapterFor($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect(azgardDriver()->override($user, $scope))->toBeNull()
        ->and(azgardDriver()->resolve(Subject::ofModel($user), $user, $scope)?->role->key)->toBe(RoleRegistry::OWNER);
});

it('never overrides for a non super-admin', function (): void {
    $user = SpyAzgardUser::make()->grantOnEntities(['documents.view']);
    bindAdapterFor($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect(azgardDriver()->override($user, $scope))->toBeNull();
});

it('folds a workspace-scoped permission into the matching role', function (): void {
    // documents.update is the Writer probe (Edit → documents.update) in the ability map.
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 1, ['documents.update']);
    bindAdapterFor($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);
    $decision = azgardDriver()->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->role->key)->toBe(RoleRegistry::WRITER);
});

it('yields no decision when the workspace grants nothing', function (): void {
    $user = SpyAzgardUser::make();
    bindAdapterFor($user);

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect(azgardDriver()->resolve(Subject::ofModel($user), $user, $scope))->toBeNull();
});

it('gates workspace creation through the create permission', function (): void {
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 5, ['documents.create']);
    bindAdapterFor($user);

    expect(azgardDriver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 5), driveId: 1)))->toBeTrue()
        ->and(azgardDriver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 6), driveId: 1)))->toBeFalse();
});

it('exposes only the actor in its subject-set', function (): void {
    $user = SpyAzgardUser::make(3);
    bindAdapterFor($user);

    $subjects = azgardDriver()->subjectsFor($user);

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});
