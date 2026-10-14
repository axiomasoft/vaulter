<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Testing\FakeAzgardGuard;
use Vaulter\Azgard\Tests\Stubs\HostGuard;
use Vaulter\Azgard\Tests\Stubs\SpyAzgardUser;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The driver answers for the decision's ACTOR, whoever the request is: the default
 * AzgardGuard binding is built from the request's user, so borrowing it for another actor
 * would hand that actor the request user's rights (acting-as/preview checks), and a call
 * with no request user at all (the collaboration hub's) would be answered "no access" for
 * everybody. The package's default binding (no bindAdapterFor() here) is the point.
 */
function actorScope(): AccessScope
{
    return new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);
}

it('never lends the request user\'s rights to another actor', function (): void {
    $this->actingAs(SpyAzgardUser::make(1)->makeSuperAdmin());
    $bob = SpyAzgardUser::make(2);
    $driver = app(AzgardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($bob), $bob, actorScope()))->toBeNull()
        ->and($driver->canCreateIn($bob, actorScope()))->toBeFalse();
});

it('does not lend the request user\'s rights to an actor that is not AzGuard-backed either', function (): void {
    $this->actingAs(SpyAzgardUser::make(1)->makeSuperAdmin());
    $plain = new class extends User
    {
        protected $guarded = [];
    };
    $plain->forceFill(['id' => 3]);
    $plain->exists = true;
    $driver = app(AzgardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($plain), $plain, actorScope()))->toBeNull()
        ->and($driver->canCreateIn($plain, actorScope()))->toBeFalse();
});

it('decides for the actor when there is no request user at all (the collaboration hub)', function (): void {
    $carol = SpyAzgardUser::make(4)->grantInWorkspace('project', 9, ['documents.update', 'documents.create']);
    $driver = app(AzgardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($carol), $carol, actorScope())?->role->key)->toBe(RoleRegistry::WRITER)
        ->and($driver->canCreateIn($carol, actorScope()))->toBeTrue();
});

it('decides for the actor even when a different user is the request user', function (): void {
    $this->actingAs(SpyAzgardUser::make(1));
    $carol = SpyAzgardUser::make(4)->grantInWorkspace('project', 9, ['documents.update']);
    $driver = app(AzgardPermissionDriver::class);

    expect($driver->resolve(Subject::ofModel($carol), $carol, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});

it('asks AzGuard in the configured panel', function (): void {
    config()->set('vaulter-azgard.panel_id', 'documents-panel');
    $carol = SpyAzgardUser::make(4)->grantInWorkspace('project', 9, ['documents.update']);

    app(AzgardPermissionDriver::class)->resolve(Subject::ofModel($carol), $carol, actorScope());

    expect(array_unique(array_column($carol->permissionCalls, 'guard')))->toBe(['documents-panel']);
});

it('answers the request user through an adapter over that very actor', function (): void {
    $me = SpyAzgardUser::make(5)->grantInWorkspace('project', 9, ['documents.update']);
    $this->actingAs($me);

    expect(app(AzgardPermissionDriver::class)->resolve(Subject::ofModel($me), $me, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});

it('keeps honouring the guard the host configured, for any actor', function (): void {
    HostGuard::bind(FakeAzgardGuard::grantingWorkspace('project', 9, 'documents.update'));
    $anyone = SpyAzgardUser::make(6);

    expect(app(AzgardPermissionDriver::class)->resolve(Subject::ofModel($anyone), $anyone, actorScope())?->role->key)->toBe(RoleRegistry::WRITER);
});
