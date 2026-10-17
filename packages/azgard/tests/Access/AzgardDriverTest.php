<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\AzgardPermissionMap;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Testing\FakeAzgardGuard;
use Vaulter\Azgard\Tests\Access\AccessTestHelpers;
use Vaulter\Azgard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The AzGuard host-role driver (§6 / H.2 / ADR-0007). It talks ONLY to the Vaulter-
 * owned AzgardGuard seam, so these tests bind a FakeAzgardGuard instead of the real
 * AzGuard engine (that end-to-end lives in tests/RealAzgard); this slice proves the
 * driver logic against the seam.
 *
 * Covered: the Ability→permission map; hasPermissionIn gating the workspace-scoped role
 * lane; hasPermissionIn gating create; the SuperAdmin override; the composite order
 * (a workspace role grant raises access when no node-ACL row exists; a node-ACL deny
 * still vetoes through the AccessResolver, since the driver only RAISES access).
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'azgard');
});

/**
 * Name a FakeAzgardGuard as the host's explicit guard (the path the driver falls to when
 * the actor itself does not implement the seam).
 */
function bindAzgardGuard(AzgardGuard $guard): void
{
    HostGuard::bind($guard);
}

it('maps abilities to AzGuard permissions with the master-plan defaults', function (): void {
    expect(app(AzgardPermissionMap::class)->permissionFor(Ability::View))->toBe('documents.view')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Download))->toBe('documents.download')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Comment))->toBe('documents.comment')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Edit))->toBe('documents.update')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Create))->toBe('documents.create')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Move))->toBe('documents.update')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Copy))->toBe('documents.update')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Trash))->toBe('documents.delete')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Delete))->toBe('documents.delete')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Share))->toBe('documents.manage')
        ->and(app(AzgardPermissionMap::class)->permissionFor(Ability::Manage))->toBe('documents.manage');
});

it('honours a host override of the ability map', function (): void {
    config()->set('vaulter-azgard.permissions', ['edit' => 'host.docs.write']);

    expect(app(AzgardPermissionMap::class)->permissionFor(Ability::Edit))->toBe('host.docs.write');
});

it('never overrides: an AzGuard super-admin resolves to the owner role through decisions', function (): void {
    bindAzgardGuard(FakeAzgardGuard::superAdmin());

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect($driver->override($actor, $scope))->toBeNull()
        ->and($driver->resolve(Subject::ofModel($actor), $actor, $scope)?->role->key)->toBe(RoleRegistry::OWNER);
});

it('defers (no override) when the actor is not a super-admin', function (): void {
    bindAzgardGuard(new FakeAzgardGuard);

    $driver = app(AzgardPermissionDriver::class);
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect($driver->override(AccessTestHelpers::user(), $scope))->toBeNull();
});

it('lets a super-admin see every node through the AccessResolver', function (): void {
    bindAzgardGuard(FakeAzgardGuard::superAdmin());

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'azgard-super');

    // No ACL grant at all, yet every AzGuard decision allows the super-admin: owner role.
    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('maps a workspace-scoped AzGuard permission to a role', function (): void {
    // The host grants documents.update in this workspace → the actor gets a Writer
    // decision (Writer is gated by the Edit ability → documents.update).
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 1, 'documents.update'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    $decision = $driver->resolve(Subject::ofModel($actor), $actor, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->role->key)->toBe(RoleRegistry::WRITER);
});

it('composite order: a workspace role grant raises access when NO node-ACL row exists', function (): void {
    // documents.view granted in the workspace; there is no v_node_grants row, so the
    // access comes purely from the role lane (composite step 2, no step 1 ACL row).
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-only');

    // The guard's workspace grant must match THIS storage's owner morph (the workspace
    // coordinates the AccessScope derives from the storage owner) so hasPermissionIn hits.
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace($storage->owner_type, $storage->owner_id, 'documents.view'));

    $resolver = app(AccessResolver::class);

    expect($resolver->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeTrue()
        // …but an ability the workspace never granted (edit) stays denied.
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeFalse();
});

it('composite order: an inheritance boundary cuts node grants but not the workspace role', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child', inheritGrants: false);

    // The workspace role lane gives a previewer everywhere in this workspace; a node
    // grant gives commenter on the parent subtree, cut off at the child.
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace($storage->owner_type, $storage->owner_id, 'documents.view'));
    AccessTestHelpers::grant($parent, RoleRegistry::COMMENTER, scope: GrantScope::Subtree);

    $resolver = app(AccessResolver::class);

    expect($resolver->can(AccessTestHelpers::user(), Ability::Comment, $parent))->toBeTrue()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Comment, $child))->toBeFalse()
        // The node-blind workspace role is a union member everywhere, boundaries or not.
        ->and($resolver->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue();
});

it('gates workspace creation through hasPermissionIn with the create permission', function (): void {
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 5, 'documents.create'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();

    expect($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 5), driveId: 1)))->toBeTrue()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 6), driveId: 1)))->toBeFalse();
});

it('returns no role decision when the workspace grants nothing (pass-through with a guard)', function (): void {
    bindAzgardGuard(new FakeAzgardGuard);

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull();
});

it('is a safe pass-through (no fatal) when no explicit guard is configured', function (): void {
    HostGuard::clear();
    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1)))->toBeFalse();
});

it('returns no role decision for an ungranted owner identity', function (): void {
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 1, 'documents.view'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'other-project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull();
});

it('exposes only the actor in its subject-set (AzGuard enumerates no role subjects)', function (): void {
    $driver = app(AzgardPermissionDriver::class);
    $subjects = $driver->subjectsFor(AccessTestHelpers::user(id: 3));

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});

it('uses the actor itself as the guard when the actor implements the AzgardGuard seam', function (): void {
    // The cleaner duck-typed path: an actor that IS an AzgardGuard is used directly,
    // with no container binding.
    $actor = new class extends User implements AzgardGuard
    {
        public $timestamps = false;

        protected $guarded = [];

        public function hasScopedPermission(string $permission, object $entity): bool
        {
            return false;
        }

        public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
        {
            return $permission === 'documents.create';
        }
    };
    $actor->forceFill(['id' => 7]);
    $actor->exists = true;

    $driver = app(AzgardPermissionDriver::class);
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->override($actor, $scope))->toBeNull()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1)))->toBeTrue();
});
