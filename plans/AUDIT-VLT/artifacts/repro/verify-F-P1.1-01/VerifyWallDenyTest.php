<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

/**
 * AUDIT-VLT P1.1 repro — read-only probes of the AccessResolver algorithm (H.2).
 * NOT part of the package suite (D6): lives under plans/AUDIT-VLT/artifacts/repro.
 * Covers checklist rows C4 (storage boundary), C5 (inherit_grants=false wall),
 * C6 (wall-deny vs nearer allow) and C10 (sharedNodeIds + filterSharedWith).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Standalone lane: only v_node_grants authorise (no host roles).
    config()->set('vaulter.drivers.permission', 'database');
});

function auditResolver(): AccessResolver
{
    return app(AccessResolver::class);
}

// ── C4: a grant reachable only by crossing storage_id must NOT enter the fold.
it('C4: a cross-storage parent_id does not leak an ancestor grant', function (): void {
    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();

    // A grant-bearing node in storage B.
    $bNode = AccessTestHelpers::folder($storageB, null, 'b-owner');
    AccessTestHelpers::grant($bNode, GrantLevel::Owner, scope: GrantScope::Subtree);

    // A node in storage A whose parent_id points INTO storage B (corrupt/hostile tree):
    // the ancestor walk would reach $bNode by parent_id, but the storage boundary must cut it.
    $aNode = AccessTestHelpers::folder($storageA, $bNode, 'a-victim');

    expect(auditResolver()->can(AccessTestHelpers::user(), Ability::View, $aNode))->toBeFalse();
});

// ── C5: the wall node's OWN grants count; grants ABOVE the wall are ignored.
it('C5: inherit_grants=false is a wall — own grant counts, grants above are ignored', function (): void {
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    $wall = AccessTestHelpers::folder($storage, $top, 'wall', inheritGrants: false);
    $below = AccessTestHelpers::folder($storage, $wall, 'below');

    // Grant ABOVE the wall must not reach the wall or below.
    AccessTestHelpers::grant($top, GrantLevel::Reader, scope: GrantScope::Subtree);
    expect(auditResolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeFalse()
        ->and(auditResolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeFalse();

    // The wall's OWN subtree grant counts for the wall and below.
    AccessTestHelpers::grant($wall, GrantLevel::Reader, scope: GrantScope::Subtree);
    expect(auditResolver()->can(AccessTestHelpers::user(), Ability::View, $wall))->toBeTrue()
        ->and(auditResolver()->can(AccessTestHelpers::user(), Ability::View, $below))->toBeTrue();
});

// ── C6: the docblock claims a deny on an inherit_grants=false node is an
// "un-overridable wall" ("no farther/NEARER allow can ever pierce it"). Probe a
// NEARER allow between the target and the wall to see whether the code honours that.
it('C6: does a nearer allow pierce a deny sitting on the inherit_grants=false wall?', function (): void {
    $storage = AccessTestHelpers::storage();
    $top = AccessTestHelpers::folder($storage, null, 'top');
    // The wall carries a subtree DENY and severs inheritance from above.
    $wall = AccessTestHelpers::folder($storage, $top, 'wall', inheritGrants: false);
    $mid = AccessTestHelpers::folder($storage, $wall, 'mid');
    $target = AccessTestHelpers::folder($storage, $mid, 'target');

    // Deny READ (subtree) ON the wall itself.
    AccessTestHelpers::grant($wall, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Subtree);
    // A NEARER allow READ (subtree) on mid, between target and wall.
    AccessTestHelpers::grant($mid, GrantLevel::Reader, effect: Effect::Allow, scope: GrantScope::Subtree);

    $allowed = auditResolver()->can(AccessTestHelpers::user(), Ability::View, $target);

    // Emit the observed outcome so the finding can quote it verbatim.
    fwrite(STDERR, "\n[C6] wall-deny pierced by nearer allow => View allowed on target: ".($allowed ? 'TRUE' : 'FALSE')."\n");

    // Assert the ACTUAL code behaviour (nearer allow pierces) so the run is green and
    // the divergence from the "un-overridable wall" docblock is documented, not hidden.
    expect($allowed)->toBeTrue();
});

// ── C10: sharedNodeIds() is a raw allow-index; filterSharedWith() must drop a node
// a nearer deny revoked.
it('C10: filterSharedWith excludes a node revoked by a nearer deny', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'shared-parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'shared-child');

    // A subtree allow high up puts both nodes in the "shared with me" candidate set…
    AccessTestHelpers::grant($parent, GrantLevel::Reader, scope: GrantScope::Subtree);
    // …but a nearer deny on the child revokes visibility of the child.
    AccessTestHelpers::grant($child, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    $actor = AccessTestHelpers::user();

    // The fast index returns the raw allow-grant node ids (parent is an allow anchor).
    $ids = auditResolver()->sharedNodeIds($actor);

    // Re-resolution must exclude the child (nearer deny) and keep the parent.
    $visible = auditResolver()->filterSharedWith($actor, [$parent, $child]);
    $visibleIds = array_map(static fn ($n) => (string) $n->id, $visible);

    fwrite(STDERR, "\n[C10] sharedNodeIds=".implode(',', array_map('strval', $ids)).
        ' | filterSharedWith kept='.implode(',', $visibleIds)."\n");

    expect($visibleIds)->toContain((string) $parent->id)
        ->and($visibleIds)->not->toContain((string) $child->id);
});
