<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\AzGuard\AzGuardGuardAdapter;
use Vaulter\AzGuard\AzGuardPermissionDriver;
use Vaulter\AzGuard\Testing\FakeAzGuardGuard;
use Vaulter\AzGuard\Tests\Stubs\HostGuard;
use Vaulter\AzGuard\Tests\Stubs\SpyAzGuardUser;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The driver answers for the decision's ACTOR, whoever the request is: the default
 * AzGuardGuard binding is built from the request's user, so borrowing it for another actor
 * would hand that actor the request user's rights (acting-as/preview checks), and a call
 * with no request user at all (the collaboration hub's) would be answered "no access" for
 * everybody. The package's default binding (no bindAdapterFor() here) is the point.
 */
function actorScope(): AccessScope
{
    return new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);
}

it('never lends the request user\'s rights to another actor', function (): void {
    $this->actingAs(SpyAzGuardUser::make(1)->makeSuperAdmin());
    $bob = SpyAzGuardUser::make(2);
    $driver = app(AzGuardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($bob), $bob, actorScope()))->toBeNull()
        ->and($driver->canCreateIn($bob, actorScope()))->toBeFalse();
});

it('does not lend the request user\'s rights to an actor that is not AzGuard-backed either', function (): void {
    $this->actingAs(SpyAzGuardUser::make(1)->makeSuperAdmin());
    $plain = new class extends User
    {
        protected $guarded = [];
    };
    $plain->forceFill(['id' => 3]);
    $plain->exists = true;
    $driver = app(AzGuardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($plain), $plain, actorScope()))->toBeNull()
        ->and($driver->canCreateIn($plain, actorScope()))->toBeFalse();
});

it('decides for the actor when there is no request user at all (the collaboration hub)', function (): void {
    $carol = SpyAzGuardUser::make(4)->grantInWorkspace('project', 9, ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete']);
    $driver = app(AzGuardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($carol), $carol, actorScope())?->role->key)->toBe(RoleRegistry::WRITER)
        ->and($driver->canCreateIn($carol, actorScope()))->toBeTrue();
});

it('decides for the actor even when a different user is the request user', function (): void {
    $this->actingAs(SpyAzGuardUser::make(1));
    $carol = SpyAzGuardUser::make(4)->grantInWorkspace('project', 9, ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete']);
    $driver = app(AzGuardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($carol), $carol, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});

it('asks AzGuard in the configured panel', function (): void {
    config()->set('vaulter-azguard.panel_id', 'documents-panel');
    $carol = SpyAzGuardUser::make(4)->grantInWorkspace('project', 9, ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete']);

    app(AzGuardPermissionDriver::class)->resolve(Subject::ofModel($carol), $carol, actorScope());

    expect(array_unique(array_column($carol->permissionCalls, 'guard')))->toBe(['documents-panel']);
});

it('answers the request user through an adapter over that very actor', function (): void {
    $me = SpyAzGuardUser::make(5)->grantInWorkspace('project', 9, ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete']);
    $this->actingAs($me);

    expect(app(AzGuardPermissionDriver::class)->resolve(Subject::ofModel($me), $me, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});

it('keeps honouring the guard the host configured, for any actor', function (): void {
    HostGuard::bind(new FakeAzGuardGuard(workspaceGrants: array_map(static fn (string $permission): array => ['project', 9, $permission], ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete'])));
    $anyone = SpyAzGuardUser::make(6);

    expect(app(AzGuardPermissionDriver::class)->resolve(Subject::ofModel($anyone), $anyone, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});

it('keeps two target actors separate through the same explicit guard regardless of the request user', function (): void {
    $requestUser = SpyAzGuardUser::make(1)->makeSuperAdmin();
    $this->actingAs($requestUser);
    HostGuard::bind(new AzGuardGuardAdapter);
    $allowed = SpyAzGuardUser::make(7)->makeSuperAdmin();
    $denied = SpyAzGuardUser::make(8);
    $driver = app(AzGuardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($denied), $denied, actorScope()))->toBeNull()
        ->and($driver->canCreateIn($denied, actorScope()))->toBeFalse()
        ->and($driver->resolve(Subject::ofModel($allowed), $allowed, actorScope())?->role->key)->toBe(RoleRegistry::OWNER)
        ->and($driver->canCreateIn($allowed, actorScope()))->toBeTrue()
        ->and($driver->resolve(Subject::ofModel($denied), $denied, actorScope()))->toBeNull();

    auth()->forgetGuards();
    expect($driver->resolve(Subject::ofModel($allowed), $allowed, actorScope())?->role->key)->toBe(RoleRegistry::OWNER)
        ->and($driver->resolve(Subject::ofModel($denied), $denied, actorScope()))->toBeNull();
});
