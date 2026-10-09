<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\NullAzgardGuard;
use Vaulter\Azgard\Tests\Stubs\SpyAzgardUser;
use Vaulter\Azgard\Tests\Stubs\WorkspaceStub;

/**
 * The adapter's job is a faithful translation of the Vaulter AzgardGuard seam onto the
 * host user's AzGuard v1 subject methods. These pin each translation directly (no DB, no
 * driver).
 */
it('forwards an entity check as hasPermission on that resource, threading the configured panel', function (): void {
    $user = SpyAzgardUser::make()->grantOnEntities(['documents.view']);
    $entity = (new WorkspaceStub)->forceFill(['id' => 5]);
    $adapter = new AzgardGuardAdapter($user, panelId: 'app');

    expect($adapter->hasScopedPermission('documents.view', $entity))->toBeTrue()
        ->and($adapter->hasScopedPermission('documents.update', $entity))->toBeFalse();

    // The exact forwarded arguments — same entity instance, same panel.
    expect($user->permissionCalls[0]['permission'])->toBe('documents.view')
        ->and($user->permissionCalls[0]['on'])->toBe($entity)
        ->and($user->permissionCalls[0]['guard'])->toBe('app');
});

it('narrows a non-Model entity to false WITHOUT touching the user', function (): void {
    $user = SpyAzgardUser::make()->grantOnEntities(['documents.view']);
    $adapter = new AzgardGuardAdapter($user);

    // A plain object is not an Eloquent Model — AzGuard places model rows, so the
    // adapter refuses it up front rather than forwarding a bad argument.
    expect($adapter->hasScopedPermission('documents.view', new stdClass))->toBeFalse()
        ->and($user->permissionCalls)->toBe([]);
});

it('forwards a workspace check as hasPermission in the assignment scope', function (): void {
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 9, ['documents.update']);
    $adapter = new AzgardGuardAdapter($user, panelId: 'app');

    expect($adapter->hasPermissionIn('project', 9, 'documents.update'))->toBeTrue()
        ->and($adapter->hasPermissionIn('project', 9, 'documents.view'))->toBeFalse()
        ->and($adapter->hasPermissionIn('project', 10, 'documents.update'))->toBeFalse();

    $call = $user->permissionCalls[0];

    expect($call['permission'])->toBe('documents.update')
        ->and($call['on'])->toBeInstanceOf(AssignmentScopeRef::class)
        ->and($call['on']?->equals(AssignmentScopeRef::of('project', 9)))->toBeTrue()
        ->and($call['guard'])->toBe('app');
});

it('the null guard denies every probe (safe pass-through)', function (): void {
    $guard = new NullAzgardGuard;

    expect($guard->hasScopedPermission('documents.view', new WorkspaceStub))->toBeFalse()
        ->and($guard->hasPermissionIn('project', 1, 'documents.update'))->toBeFalse();
});
