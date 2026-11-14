<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Vaulter\AzGuard\AzGuardGuardAdapter;
use Vaulter\AzGuard\NullAzGuardGuard;
use Vaulter\AzGuard\Tests\Stubs\SpyAzGuardUser;
use Vaulter\AzGuard\Tests\Stubs\WorkspaceStub;

/**
 * The adapter's job is a faithful translation of the Vaulter AzGuardGuard seam onto the
 * host user's AzGuard v1 subject methods. These pin each translation directly (no DB, no
 * driver).
 */
it('forwards an entity check as hasPermission on that resource, threading the configured panel', function (): void {
    $user = SpyAzGuardUser::make()->grantOnEntities(['documents.view']);
    $entity = (new WorkspaceStub)->forceFill(['id' => 5]);
    $adapter = new AzGuardGuardAdapter(panelId: 'app');

    expect($adapter->hasScopedPermission($user, 'documents.view', $entity))->toBeTrue()
        ->and($adapter->hasScopedPermission($user, 'documents.update', $entity))->toBeFalse();

    // The exact forwarded arguments — same entity instance, same panel.
    expect($user->permissionCalls[0]['permission'])->toBe('documents.view')
        ->and($user->permissionCalls[0]['on'])->toBe($entity)
        ->and($user->permissionCalls[0]['guard'])->toBe('app');
});

it('narrows a non-Model entity to false WITHOUT touching the user', function (): void {
    $user = SpyAzGuardUser::make()->grantOnEntities(['documents.view']);
    $adapter = new AzGuardGuardAdapter;

    // A plain object is not an Eloquent Model — AzGuard places model rows, so the
    // adapter refuses it up front rather than forwarding a bad argument.
    expect($adapter->hasScopedPermission($user, 'documents.view', new stdClass))->toBeFalse()
        ->and($user->permissionCalls)->toBe([]);
});

it('forwards a workspace check as hasPermission in the assignment scope', function (): void {
    $user = SpyAzGuardUser::make()->grantInWorkspace('project', 9, ['documents.update']);
    $adapter = new AzGuardGuardAdapter(panelId: 'app');

    expect($adapter->hasPermissionIn($user, 'project', 9, 'documents.update'))->toBeTrue()
        ->and($adapter->hasPermissionIn($user, 'project', 9, 'documents.view'))->toBeFalse()
        ->and($adapter->hasPermissionIn($user, 'project', 10, 'documents.update'))->toBeFalse();

    $call = $user->permissionCalls[0];

    expect($call['permission'])->toBe('documents.update')
        ->and($call['on'])->toBeInstanceOf(AssignmentScopeRef::class)
        ->and($call['on']?->equals(AssignmentScopeRef::of('project', 9)))->toBeTrue()
        ->and($call['guard'])->toBe('app');
});

it('the null guard denies every probe (safe pass-through)', function (): void {
    $guard = new NullAzGuardGuard;
    $user = SpyAzGuardUser::make();

    expect($guard->hasScopedPermission($user, 'documents.view', new WorkspaceStub))->toBeFalse()
        ->and($guard->hasPermissionIn($user, 'project', 1, 'documents.update'))->toBeFalse();
});
