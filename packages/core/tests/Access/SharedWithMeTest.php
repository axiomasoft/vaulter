<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
});

it('lists the nodes a subject is granted access to', function (): void {
    $storage = AccessTestHelpers::storage();
    $a = AccessTestHelpers::folder($storage, null, 'a');
    $b = AccessTestHelpers::folder($storage, null, 'b');
    AccessTestHelpers::folder($storage, null, 'c'); // ungranted

    AccessTestHelpers::grant($a, GrantLevel::Reader, scope: GrantScope::Item);
    AccessTestHelpers::grant($b, GrantLevel::Reader, scope: GrantScope::Item);

    $resolver = app(AccessResolver::class);
    $ids = $resolver->sharedNodeIds(AccessTestHelpers::user());

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($a->id)
        ->and($ids)->toContain($b->id);
});

it('excludes a shared row revoked by a nearer deny on re-resolution', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    // The subject is granted on the parent (subtree) — so it appears in the raw
    // "shared" index for the parent, and the child inherits it…
    AccessTestHelpers::grant($parent, GrantLevel::Reader, scope: GrantScope::Subtree);
    // …but a nearer deny on the child revokes the child's visibility.
    AccessTestHelpers::grant($child, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();

    // Re-resolving each candidate node drops the child (the nearer deny wins).
    $candidates = Node::query()->whereIn('id', [$parent->id, $child->id])->get();
    $visible = $resolver->filterSharedWith($actor, $candidates);
    $visibleIds = array_map(static fn (Node $n): int|string => $n->id, $visible);

    expect($visibleIds)->toContain($parent->id)
        ->and($visibleIds)->not->toContain($child->id);
});
