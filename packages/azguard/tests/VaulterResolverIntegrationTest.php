<?php

declare(strict_types=1);

use Vaulter\AzGuard\AzGuardGuardAdapter;
use Vaulter\AzGuard\AzGuardPermissionDriver;
use Vaulter\AzGuard\Tests\Stubs\HostGuard;
use Vaulter\AzGuard\Tests\Stubs\SpyAzGuardUser;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The bridge driving the REAL Vaulter AzGuardPermissionDriver: the adapter is bound as
 * the host's AzGuardGuard, and the driver's every lane (the workspace-role → role fold,
 * a super-admin through the same decisions, the create gate) is exercised end-to-end. Only the
 * leaf user is a controllable double; the adapter and the resolver are production code.
 */
function bindStatelessAdapter(): void
{
    HostGuard::bind(new AzGuardGuardAdapter);
}

function azguardDriver(): AzGuardPermissionDriver
{
    return app(AzGuardPermissionDriver::class);
}

it('folds an AzGuard super-admin into the owner role through the workspace decisions, never an override', function (): void {
    $user = SpyAzGuardUser::make()->makeSuperAdmin();
    bindStatelessAdapter();

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect(azguardDriver()->override($user, $scope))->toBeNull()
        ->and(azguardDriver()->resolve(Subject::ofModel($user), $user, $scope)?->role->key)->toBe(RoleRegistry::OWNER);
});

it('never overrides for a non super-admin', function (): void {
    $user = SpyAzGuardUser::make()->grantOnEntities(['documents.view']);
    bindStatelessAdapter();

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect(azguardDriver()->override($user, $scope))->toBeNull();
});

it('folds a workspace-scoped permission into the matching role', function (): void {
    // A writer requires the permissions of its whole registered ability set.
    $user = SpyAzGuardUser::make()->grantInWorkspace('project', 1, ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete']);
    bindStatelessAdapter();

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);
    $decision = azguardDriver()->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->role->key)->toBe(RoleRegistry::WRITER);
});

it('yields no decision when the workspace grants nothing', function (): void {
    $user = SpyAzGuardUser::make();
    bindStatelessAdapter();

    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect(azguardDriver()->resolve(Subject::ofModel($user), $user, $scope))->toBeNull();
});

it('gates workspace creation through the create permission', function (): void {
    $user = SpyAzGuardUser::make()->grantInWorkspace('project', 5, ['documents.create']);
    bindStatelessAdapter();

    expect(azguardDriver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 5), driveId: 1)))->toBeTrue()
        ->and(azguardDriver()->canCreateIn($user, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 6), driveId: 1)))->toBeFalse();
});

it('exposes only the actor in its subject-set', function (): void {
    $user = SpyAzGuardUser::make(3);
    bindStatelessAdapter();

    $subjects = azguardDriver()->subjectsFor($user);

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});
