<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Auth\User;
use Vaulter\AzGuard\AzGuardPermissionDriver;
use Vaulter\AzGuard\AzGuardPermissionMap;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\AzGuard\Testing\FakeAzGuardGuard;
use Vaulter\AzGuard\Tests\Access\AccessTestHelpers;
use Vaulter\AzGuard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The AzGuard host-role driver. It talks ONLY to the Vaulter-
 * owned AzGuardGuard seam, so these tests bind a FakeAzGuardGuard instead of the real
 * AzGuard engine (that end-to-end lives in tests/RealAzGuard); this slice proves the
 * driver logic against the seam.
 *
 * Covered: the Ability→permission map; hasPermissionIn gating the workspace-scoped role
 * lane; hasPermissionIn gating create; the SuperAdmin override; the composite order
 * (a workspace role grant raises access when no node-ACL row exists; a node-ACL deny
 * still vetoes through the AccessResolver, since the driver only RAISES access).
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'azguard');
});

/**
 * Name a FakeAzGuardGuard as the host's explicit guard (the path the driver falls to when
 * the actor itself does not implement the seam).
 */
function bindAzGuardGuard(AzGuardGuard $guard): void
{
    HostGuard::bind($guard);
}

it('maps abilities to AzGuard permissions with the master-plan defaults', function (): void {
    expect(app(AzGuardPermissionMap::class)->permissionFor(Ability::View))->toBe('documents.view')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Download))->toBe('documents.download')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Comment))->toBe('documents.comment')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Edit))->toBe('documents.update')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Create))->toBe('documents.create')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Move))->toBe('documents.update')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Copy))->toBe('documents.update')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Trash))->toBe('documents.delete')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Delete))->toBe('documents.delete')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Share))->toBe('documents.manage')
        ->and(app(AzGuardPermissionMap::class)->permissionFor(Ability::Manage))->toBe('documents.manage');
});

it('honours a host override of the ability map', function (): void {
    config()->set('vaulter-azguard.permissions', ['edit' => 'host.docs.write']);

    expect(app(AzGuardPermissionMap::class)->permissionFor(Ability::Edit))->toBe('host.docs.write');
});

it('never overrides: an AzGuard super-admin resolves to the owner role through decisions', function (): void {
    bindAzGuardGuard(FakeAzGuardGuard::superAdmin());

    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect($driver->override($actor, $scope))->toBeNull()
        ->and($driver->resolve(Subject::ofModel($actor), $actor, $scope)?->role->key)->toBe(RoleRegistry::OWNER);
});

it('defers (no override) when the actor is not a super-admin', function (): void {
    bindAzGuardGuard(new FakeAzGuardGuard);

    $driver = app(AzGuardPermissionDriver::class);
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 9), driveId: 1);

    expect($driver->override(AccessTestHelpers::user(), $scope))->toBeNull();
});

it('lets a super-admin see every node through the AccessResolver', function (): void {
    bindAzGuardGuard(FakeAzGuardGuard::superAdmin());

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'azguard-super');

    // No ACL grant at all, yet every AzGuard decision allows the super-admin: owner role.
    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('maps a workspace-scoped AzGuard permission to a role', function (): void {
    // The host grants every mapped Writer ability in this workspace.
    bindAzGuardGuard(new FakeAzGuardGuard(workspaceGrants: array_map(static fn (string $permission): array => ['project', 1, $permission], ['documents.view', 'documents.download', 'documents.comment', 'documents.update', 'documents.create', 'documents.delete'])));

    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    $decision = $driver->resolve(Subject::ofModel($actor), $actor, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->role->key)->toBe(RoleRegistry::WRITER);
});

it('does not turn View and Comment without Download into a commenter grant', function (bool $view): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'comment without download');
    $grants = [
        [$storage->owner_type, $storage->owner_id, 'documents.comment'],
    ];
    if ($view) {
        $grants[] = [$storage->owner_type, $storage->owner_id, 'documents.view'];
    }
    bindAzGuardGuard(new FakeAzGuardGuard(workspaceGrants: $grants));
    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    expect($resolver->can($actor, Ability::View, $folder))->toBe($view)
        ->and($resolver->can($actor, Ability::Comment, $folder))->toBeFalse()
        ->and($resolver->can($actor, Ability::Download, $folder))->toBeFalse()
        ->and($resolver->can($actor, Ability::Edit, $folder))->toBeFalse();
})->with([false, true]);

it('grants no role when a required ability has no permission mapping', function (): void {
    config()->set('vaulter-azguard.permissions', ['edit' => 'host.docs.write']);
    bindAzGuardGuard(FakeAzGuardGuard::superAdmin());
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect(app(AzGuardPermissionDriver::class)->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull();
});

it('grants the complete registered role when every mapped ability is allowed', function (string $role): void {
    $map = [];
    foreach (Ability::cases() as $ability) {
        $map[$ability->value] = 'host.'.$ability->value;
    }
    config()->set('vaulter-azguard.permissions', $map);
    $expected = (new RoleRegistry)->latest($role);
    $grants = array_map(static fn (Ability $ability): array => ['project', 1, 'host.'.$ability->value], $expected->abilities);
    bindAzGuardGuard(new FakeAzGuardGuard(workspaceGrants: $grants));
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect(app(AzGuardPermissionDriver::class)->resolve(Subject::ofModel($actor), $actor, $scope)?->role)->toBe($expected);
})->with((new RoleRegistry)->keys());

it('composite order: a workspace role grant raises access when NO node-ACL row exists', function (): void {
    // documents.view granted in the workspace; there is no v_node_grants row, so the
    // access comes purely from the role lane (composite step 2, no step 1 ACL row).
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-only');

    // The guard's workspace grant must match THIS storage's owner morph (the workspace
    // coordinates the AccessScope derives from the storage owner) so hasPermissionIn hits.
    bindAzGuardGuard(FakeAzGuardGuard::grantingWorkspace($storage->owner_type, $storage->owner_id, 'documents.view'));

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
    bindAzGuardGuard(FakeAzGuardGuard::grantingWorkspace($storage->owner_type, $storage->owner_id, 'documents.view'));
    AccessTestHelpers::grant($parent, RoleRegistry::COMMENTER, scope: GrantScope::Subtree);

    $resolver = app(AccessResolver::class);

    expect($resolver->can(AccessTestHelpers::user(), Ability::Comment, $parent))->toBeTrue()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Comment, $child))->toBeFalse()
        // The node-blind workspace role is a union member everywhere, boundaries or not.
        ->and($resolver->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue();
});

it('gates workspace creation through hasPermissionIn with the create permission', function (): void {
    bindAzGuardGuard(FakeAzGuardGuard::grantingWorkspace('project', 5, 'documents.create'));

    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();

    expect($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 5), driveId: 1)))->toBeTrue()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 6), driveId: 1)))->toBeFalse();
});

it('returns no role decision when the workspace grants nothing (pass-through with a guard)', function (): void {
    bindAzGuardGuard(new FakeAzGuardGuard);

    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull();
});

it('is a safe pass-through (no fatal) when no explicit guard is configured', function (): void {
    HostGuard::clear();
    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1)))->toBeFalse();
});

it('returns no role decision for an ungranted owner identity', function (): void {
    bindAzGuardGuard(FakeAzGuardGuard::grantingWorkspace('project', 1, 'documents.view'));

    $driver = app(AzGuardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'other-project', id: 1), driveId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull();
});

it('exposes only the actor in its subject-set (AzGuard enumerates no role subjects)', function (): void {
    $driver = app(AzGuardPermissionDriver::class);
    $subjects = $driver->subjectsFor(AccessTestHelpers::user(id: 3));

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});

it('uses the actor itself as the guard when the actor implements the AzGuardGuard seam', function (): void {
    // The cleaner duck-typed path: an actor that IS an AzGuardGuard is used directly,
    // with no container binding.
    $actor = new class extends User implements AzGuardGuard
    {
        public $timestamps = false;

        protected $guarded = [];

        public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool
        {
            return false;
        }

        public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool
        {
            return $actor === $this && $permission === 'documents.create';
        }
    };
    $actor->forceFill(['id' => 7]);
    $actor->exists = true;

    $driver = app(AzGuardPermissionDriver::class);
    $scope = new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1);

    expect($driver->override($actor, $scope))->toBeNull()
        ->and($driver->canCreateIn($actor, new AccessScope(profile: 'default', driveOwner: new OwnerRef(type: 'project', id: 1), driveId: 1)))->toBeTrue();
});
