<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\RoleStubDriver;

/**
 * The 1.0 effective-access algorithm (PLAN2.D45). Every clause gets one focused
 * row: direct grant, inherited subtree grant, inheritance boundary, union of roles
 * across grants and the host role lane, read-time expiry, the drive boundary and
 * the host override in both directions. The Database driver runs standalone (no
 * host roles), so a grant is the ONLY thing that authorises here.
 */
beforeEach(function (): void {
    // The standalone lane: decisions come purely from v_node_grants.
    config()->set('vaulter.access.permission_driver', 'database');
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
    config()->set('vaulter.access.permission_drivers.stub', RoleStubDriver::class);
    config()->set('vaulter.access.permission_driver', 'stub');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
}

it('grants a direct role on the node itself', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'direct');
    AccessTestHelpers::grant($folder, RoleRegistry::WRITER, scope: GrantScope::Item);

    $actor = AccessTestHelpers::user();

    expect(resolver()->can($actor, Ability::View, $folder))->toBeTrue()
        ->and(resolver()->can($actor, Ability::Edit, $folder))->toBeTrue();
});

it('denies an actor with no grant at all', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'ungranted');

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('inherits a subtree grant from an ancestor down to a descendant', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue();
});

it('does NOT inherit an item-scoped ancestor grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeFalse();
});

it('stops inheriting at an inheritance boundary', function (): void {
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    // The boundary ignores grants ABOVE it; a direct grant on it still works and
    // flows below it.
    $wall = AccessTestHelpers::folder($storage, $top, 'wall', inheritGrants: false);
    $below = AccessTestHelpers::folder($storage, $wall, 'below');

    AccessTestHelpers::grant($top, RoleRegistry::READER, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeFalse();

    AccessTestHelpers::grant($wall, RoleRegistry::READER, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeTrue()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeTrue();
});

it('applies a direct grant on a node below a boundary that cuts the parent grant off', function (): void {
    // Formerly: a nearer explicit allow pierces a farther inherited deny.
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child', inheritGrants: false);

    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Subtree);
    AccessTestHelpers::grant($child, RoleRegistry::READER, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeTrue()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $child))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $parent))->toBeTrue();
});

it('removes an inherited role from a node by restricting its inheritance', function (): void {
    // Formerly: a nearer deny beats a farther allow (deny-wins).
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Subtree);
    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $child))->toBeTrue();

    $child->forceFill(['inherit_grants' => false])->save();

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $child))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $child))->toBeFalse();
});

it('unions the roles of every grant that applies, whatever the row order', function (
    bool $nearFirst,
): void {
    // Formerly: a same-node deny vetoes a same-node allow regardless of row order.
    // Positive grants cannot cancel each other: the nearer, narrower grant never
    // hides the farther, broader one.
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $folder = AccessTestHelpers::folder($storage, $parent, 'same-node');

    if ($nearFirst) {
        AccessTestHelpers::grant($folder, RoleRegistry::PREVIEWER, scope: GrantScope::Item);
        AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Subtree);
    } else {
        AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Subtree);
        AccessTestHelpers::grant($folder, RoleRegistry::PREVIEWER, scope: GrantScope::Item);
    }

    $set = resolver()->effectiveGrants(AccessTestHelpers::user(), $folder);
    expect($set->allows(Ability::View))->toBeTrue()
        ->and($set->allows(Ability::Download))->toBeTrue()
        ->and($set->allows(Ability::Edit))->toBeTrue()
        ->and($set->allows(Ability::Share))->toBeFalse();
})->with([
    'nearer grant written first' => [true],
    'farther grant written first' => [false],
]);

it('lets an administrative override block survive a host role and grants', function (): void {
    // Formerly: the final veto — a deny survives a host role grant.
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'vetoed');
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);

    useRoleDriver(new RoleStubDriver(role: RoleRegistry::WRITER, overrideEffect: Effect::Deny));

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('a host role grant raises access on its own', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'roled');

    useRoleDriver(new RoleStubDriver(role: RoleRegistry::WRITER));

    expect(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $folder))->toBeTrue()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::Share, $folder))->toBeFalse();
});

it('unions the host role with the node grants', function (): void {
    // Formerly: ORs the bitmask and maxes the level across multiple allow grants.
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'mixed');
    AccessTestHelpers::grant($folder, RoleRegistry::ORGANIZER, scope: GrantScope::Item);

    useRoleDriver(new RoleStubDriver(role: RoleRegistry::COMMENTER));
    $grants = resolver()->effectiveGrants(AccessTestHelpers::user(), $folder);

    expect($grants->allows(Ability::Comment))->toBeTrue()
        ->and($grants->allows(Ability::Share))->toBeTrue()
        ->and($grants->allows(Ability::Manage))->toBeFalse()
        ->and($grants->allowedBits)->toBe((new RoleRegistry)->latest(RoleRegistry::ORGANIZER)->bits);
});

it('ignores an expired grant (read-time expiry)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'expired');

    AccessTestHelpers::grant($folder, RoleRegistry::WRITER, scope: GrantScope::Item, expiresAt: Carbon::now()->subHour());

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('honours a still-valid time-boxed grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'valid');

    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item, expiresAt: Carbon::now()->addHour());

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeTrue();
});

it('never applies a grant from another drive', function (): void {
    $storageA = AccessTestHelpers::storage();
    $folderA = AccessTestHelpers::folder($storageA, null, 'a');

    $storageB = AccessTestHelpers::storage();
    $folderB = AccessTestHelpers::folder($storageB, null, 'b');

    AccessTestHelpers::grant($folderB, RoleRegistry::OWNER, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folderA))->toBeFalse();
});

it('honours an absolute host override in both directions', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'override');

    useRoleDriver(new RoleStubDriver(overrideEffect: Effect::Allow));
    expect(resolver()->can(AccessTestHelpers::user(), Ability::Manage, $folder))->toBeTrue();

    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);
    useRoleDriver(new RoleStubDriver(overrideEffect: Effect::Deny));
    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $folder))->toBeFalse();
});

it('gives each role exactly its abilities', function (
    string $role,
    Ability $ability,
    bool $expected,
): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'bits');
    AccessTestHelpers::grant($folder, $role, scope: GrantScope::Item);

    expect(resolver()->can(AccessTestHelpers::user(), $ability, $folder))->toBe($expected);
})->with([
    'previewer can view' => [RoleRegistry::PREVIEWER, Ability::View, true],
    'previewer cannot download' => [RoleRegistry::PREVIEWER, Ability::Download, false],
    'reader can download' => [RoleRegistry::READER, Ability::Download, true],
    'reader cannot edit' => [RoleRegistry::READER, Ability::Edit, false],
    'reader cannot comment' => [RoleRegistry::READER, Ability::Comment, false],
    'commenter can comment' => [RoleRegistry::COMMENTER, Ability::Comment, true],
    'writer can edit' => [RoleRegistry::WRITER, Ability::Edit, true],
    'writer can create' => [RoleRegistry::WRITER, Ability::Create, true],
    'writer can trash' => [RoleRegistry::WRITER, Ability::Trash, true],
    'writer cannot share' => [RoleRegistry::WRITER, Ability::Share, false],
    'organizer can share' => [RoleRegistry::ORGANIZER, Ability::Share, true],
    'organizer cannot delete' => [RoleRegistry::ORGANIZER, Ability::Delete, false],
    'organizer cannot manage' => [RoleRegistry::ORGANIZER, Ability::Manage, false],
    'owner can delete' => [RoleRegistry::OWNER, Ability::Delete, true],
    'owner can manage' => [RoleRegistry::OWNER, Ability::Manage, true],
]);

it('applies a subtree grant below a boundary to everything under it', function (): void {
    // Formerly F-P1.1-01: a nearer allow pierces a deny sitting ON the wall.
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    $wall = AccessTestHelpers::folder($storage, $top, 'wall', inheritGrants: false);
    $mid = AccessTestHelpers::folder($storage, $wall, 'mid');
    $target = AccessTestHelpers::folder($storage, $mid, 'target');

    AccessTestHelpers::grant($top, RoleRegistry::OWNER, scope: GrantScope::Subtree);
    AccessTestHelpers::grant($mid, RoleRegistry::READER, scope: GrantScope::Subtree);

    expect(resolver()->can(AccessTestHelpers::user(), Ability::View, $target))->toBeTrue()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::Edit, $target))->toBeFalse()
        ->and(resolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeFalse();
});

it('AccessResolver docblocks no longer claim an un-overridable wall, F-P1.1-01', function (): void {
    $file = (new ReflectionClass(AccessResolver::class))->getFileName();
    $source = file_get_contents($file);

    expect($source)->not->toContain('un-overridable wall')
        ->and($source)->not->toContain('can ever pierce')
        ->and($source)->not->toContain('max(');
});

it('explain() lists every applying role with its origin and the boundary that stopped the walk', function (): void {
    // Formerly F-P1.1-02: the deciding ACE chain for a deny-vetoed ability.
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    $parent = AccessTestHelpers::folder($storage, $top, 'parent', inheritGrants: false);
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    AccessTestHelpers::grant($top, RoleRegistry::OWNER, scope: GrantScope::Subtree);
    $inherited = AccessTestHelpers::grant($parent, RoleRegistry::COMMENTER, scope: GrantScope::Subtree, expiresAt: Carbon::now()->addDay());
    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Item);
    $direct = AccessTestHelpers::grant($child, RoleRegistry::PREVIEWER, scope: GrantScope::Item);

    $explanation = resolver()->explain(AccessTestHelpers::user(), Ability::Edit, $child);

    expect($explanation->allowed)->toBeFalse()
        ->and($explanation->reason)->toBe(AccessExplanation::REASON_NO_GRANT)
        ->and($explanation->ability)->toBe(Ability::Edit)
        ->and($explanation->restrictions)->toBe([])
        ->and((string) $explanation->boundaryNodeId)->toBe((string) $parent->id)
        ->and($explanation->sources)->toHaveCount(2);

    [$nearest, $farther] = $explanation->sources;
    expect((string) $nearest->grantId)->toBe((string) $direct->id)
        ->and($nearest->role)->toBe(RoleRegistry::PREVIEWER)
        ->and($nearest->inherited)->toBeFalse()
        ->and((string) $farther->grantId)->toBe((string) $inherited->id)
        ->and($farther->role)->toBe(RoleRegistry::COMMENTER)
        ->and($farther->roleVersion)->toBe(1)
        ->and((string) $farther->originNodeId)->toBe((string) $parent->id)
        ->and($farther->inherited)->toBeTrue()
        ->and($farther->expiresAt?->equalTo($inherited->expires_at))->toBeTrue();
});

it('explain() reports a granted ability with reason=allow and names the host role source', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'granted');
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);
    useRoleDriver(new RoleStubDriver(role: RoleRegistry::WRITER));

    $explanation = resolver()->explain(AccessTestHelpers::user(), Ability::Edit, $folder);

    expect($explanation->allowed)->toBeTrue()
        ->and($explanation->reason)->toBe(AccessExplanation::REASON_ALLOW)
        ->and($explanation->boundaryNodeId)->toBeNull()
        ->and(array_map(static fn ($source): string => $source->role, $explanation->sources))->toBe([RoleRegistry::READER, RoleRegistry::WRITER])
        ->and($explanation->sources[1]->grantId)->toBeNull();
});

it('explain() names an administrative block as a restriction', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'blocked');
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);
    useRoleDriver(new RoleStubDriver(overrideEffect: Effect::Deny));

    $explanation = resolver()->explain(AccessTestHelpers::user(), Ability::View, $folder);

    expect($explanation->allowed)->toBeFalse()
        ->and($explanation->reason)->toBe(AccessExplanation::REASON_OVERRIDE_DENY)
        ->and($explanation->restrictions)->toBe([AccessExplanation::RESTRICTION_OVERRIDE_DENY]);
});
