<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Enums\Ability;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The Gate host-role driver (§6 / H.2): a super ability grants the override; the
 * level→ability map gates a role decision; the create ability gates canCreate. The
 * Gate is asked node-blind (panel/workspace context, never a Node), exercised here
 * by selecting the 'gate' driver and defining the matching Laravel gates.
 */
beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'gate');
});

it('grants an absolute override from a super-admin gate', function (): void {
    Gate::define('vaulter.super', fn (TestUser $user): bool => $user->getKey() === 1);

    $driver = new GatePermissionResolver;
    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 9);

    expect($driver->override(AccessTestHelpers::user(id: 1), $scope))->toBe(Effect::Allow)
        ->and($driver->override(AccessTestHelpers::user(id: 2), $scope))->toBeNull();
});

it('lets a super-admin gate see every node through the AccessResolver', function (): void {
    Gate::define('vaulter.super', fn (TestUser $user): bool => true);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'gated');

    // No ACL grant at all, yet the super override short-circuits to full access.
    expect(app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();
});

it('maps a gate ability to a role-grant level', function (): void {
    // Only the writer-level ability is granted → the actor gets a Writer decision.
    Gate::define('vaulter.edit', fn (TestUser $user): bool => true);

    $driver = new GatePermissionResolver;
    $actor = AccessTestHelpers::user();
    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    $decision = $driver->resolve(Subject::ofModel($actor), $actor, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer)
        ->and($decision->isAllow())->toBeTrue();
});

it('lets a gate-mapped ability raise access in the AccessResolver with no ACL row', function (): void {
    // The host's gate says this user may edit anywhere in the workspace; there is no
    // v_node_grants row, so the access comes purely from the role lane.
    Gate::define('vaulter.edit', fn (TestUser $user): bool => true);
    Gate::define('vaulter.view', fn (TestUser $user): bool => true);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-only');

    $resolver = app(AccessResolver::class);

    expect($resolver->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeTrue()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeTrue()
        // …but a level it was never granted (delete) stays denied.
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Delete, $folder))->toBeFalse();
});

it('returns no role decision when no mapped ability is granted (pass-through)', function (): void {
    $driver = new GatePermissionResolver;
    $actor = AccessTestHelpers::user();
    $scope = new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect($driver->resolve(Subject::ofModel($actor), $actor, $scope))->toBeNull()
        ->and($driver->override($actor, $scope))->toBeNull();
});

it('gates workspace creation through the configured create ability', function (): void {
    // The create ability receives [workspaceType, workspaceId]; allow only project 5.
    Gate::define('vaulter.create', fn (TestUser $user, string $type, int|string $id): bool => $id === 5);

    $driver = new GatePermissionResolver;
    $actor = AccessTestHelpers::user();

    expect($driver->canCreateInWorkspace($actor, 'project', 5))->toBeTrue()
        ->and($driver->canCreateInWorkspace($actor, 'project', 6))->toBeFalse();
});

it('the gate ability check is node-blind — it receives the workspace context only', function (): void {
    $seen = null;

    Gate::define('vaulter.edit', function (TestUser $user, array $context) use (&$seen): bool {
        $seen = $context;

        return true;
    });

    $driver = new GatePermissionResolver;
    $actor = AccessTestHelpers::user();
    $scope = new AclScope(panel: 'team', workspaceType: 'project', workspaceId: 42);

    $driver->resolve(Subject::ofModel($actor), $actor, $scope);

    expect($seen)->toBe([
        'panel' => 'team',
        'workspace_type' => 'project',
        'workspace_id' => 42,
    ]);
});

it('exposes only the actor in its subject-set (the Gate has no role subjects)', function (): void {
    $driver = new GatePermissionResolver;
    $subjects = $driver->subjectsFor(AccessTestHelpers::user(id: 3));

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});
