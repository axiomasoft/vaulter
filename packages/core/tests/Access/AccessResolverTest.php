<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Enums\Ability;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Enums\Permission;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\RoleStubDriver;

/**
 * The M2a effective-permission algorithm (master plan §2.6 / H.2). These are the
 * crux table-driven tests: every clause of the algorithm — direct allow, inherited
 * subtree allow, cut-off, deny-wins, the final veto after a role grant, bitmask
 * OR / level max, read-time expiry, the workspace boundary, super-admin override —
 * gets one focused row. The Database driver runs standalone (no host roles), so a
 * grant is the ONLY thing that authorises here.
 */
beforeEach(function (): void {
    // The standalone lane: decisions come purely from v_node_grants.
    config()->set('vaulter.drivers.permission', 'database');
});

function resolver(): AccessResolver
{
    return app(AccessResolver::class);
}

/**
 * Swap the active host-role driver to a stub: register it under a 'stub' driver key
 * and bind the instance, then point config at it and drop the memoised manager +
 * resolver so the next resolve picks up the stub.
 */
function useRoleDriver(RoleStubDriver $driver): void
{
    app()->instance(RoleStubDriver::class, $driver);
    config()->set('vaulter.permission_drivers.stub', RoleStubDriver::class);
    config()->set('vaulter.drivers.permission', 'stub');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
}

it('grants a direct allow on the node itself', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'direct');
    AccessTestHelpers::grant($folder, GrantLevel::Writer, scope: GrantScope::Item);

    $actor = AccessTestHelpers::user();

    expect(resolver()->can($actor, Ability::View, $folder))->toBeTrue()
        ->and(resolver()->can($actor, Ability::Edit, $folder))->toBeTrue();
});

it('denies an actor with no grant at all', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'ungranted');

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('inherits a subtree allow from an ancestor down to a descendant', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // Subtree grant on the PARENT — flows down to the child.
    AccessTestHelpers::grant($parent, GrantLevel::Reader, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue();
});

it('does NOT inherit an item-scoped ancestor grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // Item grant on the parent only — must NOT reach the child.
    AccessTestHelpers::grant($parent, GrantLevel::Reader, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeFalse();
});

it('stops inheriting at an inherit_grants=false cut-off node', function (): void {
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    // The wall is a new permission root: it (and its subtree) ignore grants ABOVE
    // it. A direct grant on the wall still works; top's subtree grant does not reach
    // the wall or anything below it.
    $wall = AccessTestHelpers::folder($storage, $top, 'wall', inheritGrants: false);
    $below = AccessTestHelpers::folder($storage, $wall, 'below');

    AccessTestHelpers::grant($top, GrantLevel::Reader, scope: GrantScope::Subtree);

    // top's grant is walled off from the wall and below.
    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeFalse();

    // A direct grant on the wall authorises the wall AND inherits to below (the wall
    // only severs inheritance from ABOVE, not its own downward subtree grants).
    AccessTestHelpers::grant($wall, GrantLevel::Reader, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeTrue()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeTrue();
});

it('lets a nearer explicit allow pierce a farther inherited deny', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // Farther inherited deny on the parent…
    AccessTestHelpers::grant($parent, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Subtree);
    // …pierced by a nearer explicit allow on the child.
    AccessTestHelpers::grant($child, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue();
});

it('lets a nearer deny beat a farther allow (deny-wins)', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // Farther allow on the parent…
    AccessTestHelpers::grant($parent, GrantLevel::Writer, effect: Effect::Allow, scope: GrantScope::Subtree);
    // …revoked by a nearer deny on the child.
    AccessTestHelpers::grant($child, GrantLevel::Writer, effect: Effect::Deny, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $child))->toBeFalse();
});

it('lets a same-node deny veto a same-node allow regardless of row order', function (
    bool $allowFirst,
): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'same-node');

    // allow and deny on the SAME node for the SAME ability — the deny is an absolute
    // veto (§2.6 / H.2 step 5): a same-node allow must NEVER swallow it, no matter
    // which row was written (and therefore iterated) first.
    if ($allowFirst) {
        AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Item);
        AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);
    } else {
        AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);
        AccessTestHelpers::grant($folder, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Item);
    }

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
})->with([
    'allow row written first' => [true],
    'deny row written first' => [false],
]);

it('applies the FINAL veto: a deny survives a host role grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'vetoed');

    // A node-ACL deny on the EDIT bit (Writer).
    AccessTestHelpers::grant($folder, GrantLevel::Writer, effect: Effect::Deny, scope: GrantScope::Item);

    // A host role that WOULD grant Writer (so max(aclAllow, roleAllow) would allow
    // Edit) — but the final veto (H.2 step 5) must still deny it.
    useRoleDriver(new RoleStubDriver(roleLevel: GrantLevel::Writer));

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeFalse();
});

it('a host role grant can raise access when nothing denies it', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'roled');

    // No ACL rows at all; the host role alone grants Writer.
    useRoleDriver(new RoleStubDriver(roleLevel: GrantLevel::Writer));

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeTrue();
});

it('ORs the bitmask and maxes the level across multiple allow grants', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'mixed');

    // A Reader allow (READ) plus an extra explicit SHARE bit on a second row.
    AccessTestHelpers::grant($folder, GrantLevel::Reader, scope: GrantScope::Item);
    AccessTestHelpers::grant(
        $folder,
        GrantLevel::Reader,
        scope: GrantScope::Subtree,
        permissions: Permission::Share->value,
    );

    $grants = resolver()->effectiveGrants(AccessTestHelpers::user(), $folder);

    expect($grants->allows(Ability::View))->toBeTrue()
        ->and($grants->allows(Ability::Share))->toBeTrue()
        ->and($grants->level)->toBe(GrantLevel::Reader);
});

it('ignores an expired grant (read-time expiry)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'expired');

    AccessTestHelpers::grant(
        $folder,
        GrantLevel::Writer,
        scope: GrantScope::Item,
        expiresAt: Carbon::now()->subHour(),
    );

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('honours a still-valid time-boxed grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'valid');

    AccessTestHelpers::grant(
        $folder,
        GrantLevel::Reader,
        scope: GrantScope::Item,
        expiresAt: Carbon::now()->addHour(),
    );

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeTrue();
});

it('never applies a grant from another storage (workspace boundary)', function (): void {
    $storageA = AccessTestHelpers::storage();
    $folderA = AccessTestHelpers::folder($storageA, null, 'a');

    $storageB = AccessTestHelpers::storage();
    $folderB = AccessTestHelpers::folder($storageB, null, 'b');

    // A broad subtree allow in storage B must never authorise a node in storage A.
    AccessTestHelpers::grant($folderB, GrantLevel::Owner, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folderA))->toBeFalse();
});

it('honours an absolute super-admin override in both directions', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'override');

    // Allow override — full access regardless of (absent) grants.
    useRoleDriver(new RoleStubDriver(overrideEffect: Effect::Allow));
    expect(resolver()->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();

    // Deny override — no access even WITH an owner grant.
    AccessTestHelpers::grant($folder, GrantLevel::Owner, scope: GrantScope::Item);
    useRoleDriver(new RoleStubDriver(overrideEffect: Effect::Deny));
    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('maps each ability to the right required bits', function (
    GrantLevel $level,
    Ability $ability,
    bool $expected,
): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'bits');
    AccessTestHelpers::grant($folder, $level, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), $ability, $folder))->toBe($expected);
})->with([
    'reader can view' => [GrantLevel::Reader, Ability::View, true],
    'reader cannot edit' => [GrantLevel::Reader, Ability::Edit, false],
    'reader cannot share' => [GrantLevel::Reader, Ability::Share, false],
    'writer can edit' => [GrantLevel::Writer, Ability::Edit, true],
    'writer can create' => [GrantLevel::Writer, Ability::Create, true],
    'writer cannot delete' => [GrantLevel::Writer, Ability::Trash, false],
    'organizer can trash' => [GrantLevel::Organizer, Ability::Trash, true],
    'organizer cannot share' => [GrantLevel::Organizer, Ability::Share, false],
    'owner can share' => [GrantLevel::Owner, Ability::Share, true],
    'owner can manage' => [GrantLevel::Owner, Ability::Manage, true],
]);
