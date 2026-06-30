<?php

declare(strict_types=1);

use Vaulter\Enums\GrantScope;
use Vaulter\Enums\Permission;
use Vaulter\Facades\Vaulter;
use Vaulter\Sharing\ShareContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * The node-set bounding (§2.7 / M2c hardening): a subtree share reaches the shared
 * node and its descendants but NEVER its siblings, ancestors or a node in another
 * storage. An item share reaches exactly one node.
 */
beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
});

it('an item-scope share contains only the shared node', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    $share = ShareTestHelpers::share($parent, permissions: Permission::Read->value, scope: GrantScope::Item);
    $context = new ShareContext(share: $share, node: $parent, permissions: $share->permissions);

    expect($context->containsNode($parent))->toBeTrue()
        // A child is NOT in an item-scope node-set.
        ->and($context->containsNode($child))->toBeFalse();
});

it('a subtree share contains descendants but NOT siblings or ancestors', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    $shared = AccessTestHelpers::folder($storage, null, 'shared');
    $deepChild = AccessTestHelpers::folder($storage, $shared, 'deep');
    $grandChild = AccessTestHelpers::folder($storage, $deepChild, 'grand');

    $sibling = AccessTestHelpers::folder($storage, null, 'sibling');

    $share = ShareTestHelpers::share($shared, permissions: Permission::Read->value, scope: GrantScope::Subtree);
    $context = new ShareContext(share: $share, node: $shared, permissions: $share->permissions);

    expect($context->containsNode($shared))->toBeTrue()
        ->and($context->containsNode($deepChild))->toBeTrue()
        ->and($context->containsNode($grandChild))->toBeTrue()
        // Escape attempts: a sibling, the ancestor (root) — both rejected.
        ->and($context->containsNode($sibling))->toBeFalse()
        ->and($context->containsNode($root))->toBeFalse();
});

it('a subtree share never escapes to another storage', function (): void {
    $storageA = AccessTestHelpers::storage();
    $sharedA = AccessTestHelpers::folder($storageA, null, 'A');

    // A node in a DIFFERENT storage with the same shape must never be reachable.
    $storageB = Vaulter::for(DriveTestHelpers::host())->storage();
    $nodeB = AccessTestHelpers::folder($storageB, null, 'B');

    $share = ShareTestHelpers::share($sharedA, permissions: Permission::Read->value, scope: GrantScope::Subtree);
    $context = new ShareContext(share: $share, node: $sharedA, permissions: $share->permissions);

    expect($context->containsNode($nodeB))->toBeFalse();
});
