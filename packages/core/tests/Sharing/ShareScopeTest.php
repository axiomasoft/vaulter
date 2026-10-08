<?php

declare(strict_types=1);

use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
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

    $share = ShareTestHelpers::share($parent, permissions: (Permission::View->value | Permission::Download->value), scope: GrantScope::Item);
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

    $share = ShareTestHelpers::share($shared, permissions: (Permission::View->value | Permission::Download->value), scope: GrantScope::Subtree);
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
    $storageB = Vaulter::drive(DriveTestHelpers::host())->model();
    $nodeB = AccessTestHelpers::folder($storageB, null, 'B');

    $share = ShareTestHelpers::share($sharedA, permissions: (Permission::View->value | Permission::Download->value), scope: GrantScope::Subtree);
    $context = new ShareContext(share: $share, node: $sharedA, permissions: $share->permissions);

    expect($context->containsNode($nodeB))->toBeFalse();
});

it('a subtree share on an ancestor stops at an inheritance boundary like a grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $shared = AccessTestHelpers::folder($storage, null, 'shared');
    $open = AccessTestHelpers::folder($storage, $shared, 'open');
    $walled = AccessTestHelpers::folder($storage, $shared, 'walled', inheritGrants: false);
    $belowWall = AccessTestHelpers::folder($storage, $walled, 'below');

    $share = ShareTestHelpers::share($shared, permissions: (Permission::View->value | Permission::Download->value), scope: GrantScope::Subtree);
    $context = new ShareContext(share: $share, node: $shared, permissions: $share->permissions);

    expect($context->containsNode($open))->toBeTrue()
        ->and($context->containsNode($walled))->toBeFalse()
        ->and($context->containsNode($belowWall))->toBeFalse();

    // A link ON the boundary node itself still reaches below it.
    $onWall = ShareTestHelpers::share($walled, token: 'on-wall-token', permissions: Permission::View->value, scope: GrantScope::Subtree);
    $wallContext = new ShareContext(share: $onWall, node: $walled, permissions: $onWall->permissions);
    expect($wallContext->containsNode($walled))->toBeTrue()
        ->and($wallContext->containsNode($belowWall))->toBeTrue();
});
