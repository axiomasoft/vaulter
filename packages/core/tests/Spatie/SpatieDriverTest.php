<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\SpatieUser;

/**
 * The spatie/laravel-permission host-role driver (§6 / H.2 / D22): a role-granted
 * v_node_grants row resolves; the team-switch isolates two teams (no cross-team
 * leak); the cache is forgotten on mutation; a super-role/permission overrides. The
 * entire v8 team-switch dance lives INSIDE the driver — these tests prove it from
 * the outside.
 */

/**
 * A persisted spatie actor (the users table is created by SpatieTestCase).
 */
function spatieUser(): SpatieUser
{
    return SpatieUser::query()->create(['name' => 'Actor', 'email' => 'actor@example.test']);
}

/**
 * Set the active spatie team (the workspace) the way a "shared with me" pass would.
 */
function setTeam(int|string $teamId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
}

/**
 * Align the active spatie team with a storage's workspace (its owner id). The
 * AccessResolver derives the AclScope's workspaceId from the storage owner, so a
 * permission must be granted under THAT team for the through-resolver checks.
 */
function setTeamForStorage(Storage $storage): int|string
{
    /** @var int|string $owner */
    $owner = $storage->owner_id;

    setTeam($owner);

    return $owner;
}

it('resolves a permission granted directly to the actor (team-scoped)', function (): void {
    $user = spatieUser();
    setTeam(1);

    Permission::findOrCreate('vaulter.edit', 'web');
    $user->givePermissionTo('vaulter.edit');

    $driver = new SpatiePermissionResolver;
    $scope = new AclScope(panel: 'default', workspaceType: 'team', workspaceId: 1);

    $decision = $driver->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer);
});

it('resolves a v_node_grants row granted to a spatie ROLE via subjectsFor', function (): void {
    $user = spatieUser();
    setTeam(7);

    $role = Role::findOrCreate('editor', 'web');
    $user->assignRole($role);

    // The driver expands the actor into role Subjects keyed by the role's PRIMARY KEY
    // (the same handle every subject uses); a grant addressed to that role must therefore
    // resolve through the AccessResolver.
    $subjects = (new SpatiePermissionResolver)->subjectsFor($user);
    $roleSubjects = array_values(array_filter($subjects, fn (Subject $s): bool => $s->type === 'role'));

    expect($roleSubjects)->toHaveCount(1)
        ->and($roleSubjects[0]->id)->toBe($role->getKey());

    // Now wire a grant to that role (by its key) on a node and confirm the resolver allows it.
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-granted');

    AccessTestHelpers::grant(
        $folder,
        GrantLevel::Writer,
        scope: GrantScope::Item,
        subjectId: $role->getKey(),
        subjectType: 'role',
    );

    expect(app(AccessResolver::class)->can($user, Ability::Edit, $folder))->toBeTrue();
});

it('isolates two teams — a permission in team A does not leak into team B', function (): void {
    $user = spatieUser();

    Permission::findOrCreate('vaulter.edit', 'web');

    // Grant the edit permission ONLY in team 1.
    setTeam(1);
    $user->givePermissionTo('vaulter.edit');

    $driver = new SpatiePermissionResolver;

    $scopeA = new AclScope(panel: 'default', workspaceType: 'team', workspaceId: 1);
    $scopeB = new AclScope(panel: 'default', workspaceType: 'team', workspaceId: 2);

    // The driver runs the team-switch internally per scope: team 1 sees the grant,
    // team 2 does not — no cross-team leak.
    expect($driver->resolve(Subject::ofModel($user), $user, $scopeA))->not->toBeNull()
        ->and($driver->resolve(Subject::ofModel($user), $user, $scopeB))->toBeNull();
});

it('restores the previous team id after a scoped check (symmetric team-switch)', function (): void {
    $user = spatieUser();
    setTeam(99);

    Permission::findOrCreate('vaulter.edit', 'web');

    $driver = new SpatiePermissionResolver;
    $driver->resolve(
        Subject::ofModel($user),
        $user,
        new AclScope(panel: 'default', workspaceType: 'team', workspaceId: 3),
    );

    // The active team is exactly what it was before the scoped check — the driver
    // never leaks its temporary team id.
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe(99);
});

it('overrides through a configurable super-permission', function (): void {
    $user = spatieUser();

    // The AccessResolver derives the scope's workspace from the storage owner, so the
    // super-permission must be granted under THAT team for the override to fire.
    $storage = AccessTestHelpers::storage();
    setTeamForStorage($storage);

    Permission::findOrCreate('vaulter.super', 'web');
    $user->givePermissionTo('vaulter.super');

    $folder = AccessTestHelpers::folder($storage, null, 'super');

    // No ACL grant; the super-permission short-circuits to full access.
    config()->set('vaulter.spatie.super_permission', 'vaulter.super');

    expect(app(AccessResolver::class)->can($user, Ability::Manage, $folder))->toBeTrue();
});

it('forgets the cached permissions on mutation (no stale grant after revoke)', function (): void {
    $user = spatieUser();
    setTeam(1);

    Permission::findOrCreate('vaulter.edit', 'web');
    $user->givePermissionTo('vaulter.edit');

    $driver = new SpatiePermissionResolver;
    $scope = new AclScope(panel: 'default', workspaceType: 'team', workspaceId: 1);

    expect($driver->resolve(Subject::ofModel($user), $user, $scope))->not->toBeNull();

    // Revoke, then flush the cache through the driver's consumer seam — the next
    // resolve must NOT see the stale, team-cached permission.
    $user->revokePermissionTo('vaulter.edit');
    SpatiePermissionResolver::forgetCachedPermissions($user);

    expect($driver->resolve(Subject::ofModel($user), $user, $scope))->toBeNull();
});

it('gates workspace creation through the team-scoped create permission', function (): void {
    $user = spatieUser();

    Permission::findOrCreate('vaulter.create', 'web');

    setTeam(5);
    $user->givePermissionTo('vaulter.create');

    $driver = new SpatiePermissionResolver;

    // Created in team 5 → allowed; team 6 has no such grant → denied.
    expect($driver->canCreateInWorkspace($user, 'team', 5))->toBeTrue()
        ->and($driver->canCreateInWorkspace($user, 'team', 6))->toBeFalse();
});

it('contributes the actor plus its roles to the subject-set', function (): void {
    $user = spatieUser();
    setTeam(1);

    $user->assignRole(Role::findOrCreate('viewer', 'web'));

    $subjects = (new SpatiePermissionResolver)->subjectsFor($user);
    $types = array_map(fn (Subject $s): string => $s->type, $subjects);

    expect($types)->toContain('role')
        ->and($subjects[0]->equals(Subject::ofModel($user)))->toBeTrue();
});

it('expires a time-boxed role grant at read time', function (): void {
    Carbon::setTestNow('2026-01-01 12:00:00');

    $user = spatieUser();
    setTeam(1);

    $role = Role::findOrCreate('temp', 'web');
    $user->assignRole($role);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'expiring');

    AccessTestHelpers::grant(
        $folder,
        GrantLevel::Writer,
        scope: GrantScope::Item,
        subjectId: $role->getKey(),
        subjectType: 'role',
        expiresAt: Carbon::now()->subHour(),
    );

    // The grant is already expired → the read-time filter drops it.
    expect(app(AccessResolver::class)->can($user, Ability::Edit, $folder))->toBeFalse();

    Carbon::setTestNow();
});
