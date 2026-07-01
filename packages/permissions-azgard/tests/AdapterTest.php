<?php

declare(strict_types=1);

use Vaulter\Permissions\Azgard\AzgardGuardAdapter;
use Vaulter\Permissions\Azgard\NullAzgardGuard;
use Vaulter\Permissions\Azgard\Tests\Stubs\SpyAzgardUser;
use Vaulter\Permissions\Azgard\Tests\Stubs\WorkspaceStub;

/**
 * The adapter's job is a faithful translation of the Vaulter AzgardGuard seam onto the
 * host user's AzGuard methods. These pin each translation directly (no DB, no driver).
 */
it('forwards a scoped check to the user, threading the configured panel id', function (): void {
    $user = SpyAzgardUser::make()->grantScoped(['documents.view']);
    $entity = (new WorkspaceStub)->forceFill(['id' => 5]);
    $adapter = new AzgardGuardAdapter($user, panelId: 'documents');

    expect($adapter->hasScopedPermission('documents.view', $entity))->toBeTrue()
        ->and($adapter->hasScopedPermission('documents.update', $entity))->toBeFalse();

    // The exact forwarded arguments — same entity instance, same panel id.
    expect($user->scopedCalls[0]['permission'])->toBe('documents.view')
        ->and($user->scopedCalls[0]['entity'])->toBe($entity)
        ->and($user->scopedCalls[0]['panelId'])->toBe('documents');
});

it('narrows a non-Model entity to false WITHOUT touching the user', function (): void {
    $user = SpyAzgardUser::make()->grantScoped(['documents.view']);
    $adapter = new AzgardGuardAdapter($user);

    // A plain object is not an Eloquent Model — AzGuard scopes to model rows, so the
    // adapter refuses it up front rather than forwarding a bad argument.
    expect($adapter->hasScopedPermission('documents.view', new stdClass))->toBeFalse()
        ->and($user->scopedCalls)->toBe([]);
});

it('passes a workspace-context check straight through', function (): void {
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 9, ['documents.update']);
    $adapter = new AzgardGuardAdapter($user, panelId: 'documents');

    expect($adapter->hasPermissionIn('project', 9, 'documents.update'))->toBeTrue()
        ->and($adapter->hasPermissionIn('project', 9, 'documents.view'))->toBeFalse()
        ->and($adapter->hasPermissionIn('project', 10, 'documents.update'))->toBeFalse();

    expect($user->contextCalls[0])->toMatchArray([
        'contextType' => 'project',
        'contextId' => 9,
        'permission' => 'documents.update',
        'panelId' => 'documents',
    ]);
});

it('maps super-admin to the configurable wildcard permission probe', function (): void {
    $wildcardUser = SpyAzgardUser::make()->grantGlobal(['*']);
    $plainUser = SpyAzgardUser::make()->grantGlobal(['documents.view']);

    expect((new AzgardGuardAdapter($wildcardUser))->isSuperAdmin())->toBeTrue()
        ->and((new AzgardGuardAdapter($plainUser))->isSuperAdmin())->toBeFalse();

    // A host may rename the wildcard key.
    $customUser = SpyAzgardUser::make()->grantGlobal(['root']);
    expect((new AzgardGuardAdapter($customUser, superAdminPermission: 'root'))->isSuperAdmin())->toBeTrue();
    expect($customUser->globalCalls[0]['permission'])->toBe('root');
});

it('the null guard denies every probe (safe pass-through)', function (): void {
    $guard = new NullAzgardGuard;

    expect($guard->hasScopedPermission('documents.view', new WorkspaceStub))->toBeFalse()
        ->and($guard->hasPermissionIn('project', 1, 'documents.update'))->toBeFalse()
        ->and($guard->isSuperAdmin())->toBeFalse();
});
