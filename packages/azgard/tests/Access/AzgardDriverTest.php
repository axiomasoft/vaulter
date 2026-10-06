<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\AzgardPermissionMap;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Testing\FakeAzgardGuard;
use Vaulter\Azgard\Tests\Access\AccessTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;

/**
 * The AzGuard host-role driver (§6 / H.2 / ADR-0007). It talks ONLY to the Vaulter-
 * owned AzgardGuard seam, so these tests bind a FakeAzgardGuard — NO azguard/corex is
 * installed. Real-ecosystem integration (binding a real AzgardGuard adapter delegating
 * to AzGuard hasScopedPermission / Corex ContextGuard hasPermissionIn, plus ulid morph
 * alignment) is a HOST step; this slice proves the driver logic against the seam.
 *
 * Covered: the Ability→permission map; hasPermissionIn gating the workspace-scoped role
 * lane; hasPermissionIn gating create; the SuperAdmin override; the composite order
 * (a workspace role grant raises access when no node-ACL row exists; a node-ACL deny
 * still vetoes through the AccessResolver, since the driver only RAISES access).
 */
beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'azgard');
});

/**
 * Bind a FakeAzgardGuard as the host's AzgardGuard adapter (the container path the
 * driver falls to when the actor itself does not implement the seam).
 */
function bindAzgardGuard(AzgardGuard $guard): void
{
    app()->instance(AzgardGuard::class, $guard);
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

it('grants an absolute override from an AzGuard super-admin', function (): void {
    bindAzgardGuard(FakeAzgardGuard::superAdmin());

    $driver = app(AzgardPermissionDriver::class);
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 9);

    expect($driver->override(AccessTestHelpers::user(), $scope))->toBe(Effect::Allow);
});

it('defers (no override) when the actor is not a super-admin', function (): void {
    bindAzgardGuard(new FakeAzgardGuard);

    $driver = app(AzgardPermissionDriver::class);
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 9);

    expect($driver->override(AccessTestHelpers::user(), $scope))->toBeNull();
});

it('lets a super-admin see every node through the AccessResolver', function (): void {
    bindAzgardGuard(FakeAzgardGuard::superAdmin());

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'azgard-super');

    // No ACL grant at all, yet the super override short-circuits to full access.
    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('maps a workspace-scoped AzGuard permission to a role-grant level', function (): void {
    // The host grants documents.update in this workspace → the actor gets a Writer
    // decision (Writer is gated by the Edit ability → documents.update).
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 1, 'documents.update'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    $decision = $driver->resolve(Subject::ofModel($actor), $actor, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer)
        ->and($decision->isAllow())->toBeTrue();
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

it('composite order: a node-ACL deny still vetoes a workspace role grant via the AccessResolver', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // The workspace role lane grants edit everywhere in this workspace…
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace($storage->owner_type, $storage->owner_id, 'documents.update'));

    // …but an explicit node-ACL deny on the child must veto it (H.2 step 5 — the role
    // lane can only RAISE access, never resurrect a denied bit).
    AccessTestHelpers::grant(
        $child,
        GrantLevel::Writer,
        effect: Effect::Deny,
        scope: GrantScope::Item,
    );

    $resolver = app(AccessResolver::class);

    expect($resolver->can(AccessTestHelpers::user(), Ability::Edit, $parent))->toBeTrue()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Edit, $child))->toBeFalse();
});

it('gates workspace creation through hasPermissionIn with the create permission', function (): void {
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 5, 'documents.create'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();

    expect($driver->canCreateInWorkspace($actor, 'project', 5))->toBeTrue()
        ->and($driver->canCreateInWorkspace($actor, 'project', 6))->toBeFalse();
});

it('returns no role decision when the workspace grants nothing (pass-through with a guard)', function (): void {
    bindAzgardGuard(new FakeAzgardGuard);

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull();
});

it('is a safe pass-through (no fatal) when no AzgardGuard is bound', function (): void {
    app()->offsetUnset(AzgardGuard::class);
    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull()
        ->and($driver->canCreateInWorkspace($actor, 'project', 1))->toBeFalse();
});

it('returns no role decision for a node-blind scope with no workspace coordinates', function (): void {
    bindAzgardGuard(FakeAzgardGuard::grantingWorkspace('project', 1, 'documents.view'));

    $driver = app(AzgardPermissionDriver::class);
    $actor = AccessTestHelpers::user();
    $scope = new AccessScope(panel: 'default'); // no workspaceType/workspaceId

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
    // with no container binding. A super-admin actor short-circuits the override.
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

        public function isSuperAdmin(): bool
        {
            return true;
        }
    };
    $actor->forceFill(['id' => 7]);
    $actor->exists = true;

    $driver = app(AzgardPermissionDriver::class);
    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect($driver->override($actor, $scope))->toBe(Effect::Allow)
        ->and($driver->canCreateInWorkspace($actor, 'project', 1))->toBeTrue();
});
