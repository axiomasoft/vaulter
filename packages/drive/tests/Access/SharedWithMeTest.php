<?php

declare(strict_types=1);

use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
});

it('lists the nodes a subject is granted access to', function (): void {
    $storage = AccessTestHelpers::storage();
    $a = AccessTestHelpers::folder($storage, null, 'a');
    $b = AccessTestHelpers::folder($storage, null, 'b');
    AccessTestHelpers::folder($storage, null, 'c'); // ungranted

    AccessTestHelpers::grant($a, RoleRegistry::READER, scope: GrantScope::Item);
    AccessTestHelpers::grant($b, RoleRegistry::READER, scope: GrantScope::Item);

    $resolver = app(AccessResolver::class);
    $ids = $resolver->sharedNodeIds(AccessTestHelpers::user());

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($a->id)
        ->and($ids)->toContain($b->id);
});

it('excludes a candidate cut off by an inheritance boundary on re-resolution', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child', inheritGrants: false);

    // The subject is granted on the parent (subtree) — so it appears in the raw
    // "shared" index for the parent; the child's boundary cuts that grant off.
    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Subtree);

    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();

    // Re-resolving each candidate node drops the walled child.
    $candidates = Node::query()->whereIn('id', [$parent->id, $child->id])->get();
    $visible = $resolver->filterSharedWith($actor, $candidates);
    $visibleIds = array_map(static fn (Node $n): int|string => $n->id, $visible);

    expect($visibleIds)->toContain($parent->id)
        ->and($visibleIds)->not->toContain($child->id);
});
