<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

/**
 * Skeptic verification for F-P4.2-01, supporting probe: staudenmeir's `depth`
 * column is NEGATIVE for ancestors() (and positive for descendants()) — the
 * `depth` scope column runs the other direction depending on traversal. This is
 * why `withMaxDepth(DEFAULT_MAX_DEPTH)` (DEFAULT_MAX_DEPTH is a positive int,
 * see NodeReadRepository::DEFAULT_MAX_DEPTH = 64) is a no-op when literally
 * reused on an ancestors() call — see AncestorWalkBoundTest.php for the
 * behavioural proof.
 */
uses(TestCase::class, RefreshDatabase::class);

it('ancestors() carries negative depth, not positive', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    $node = $root;
    $levels = [];

    for ($i = 1; $i <= 5; $i++) {
        $node = AccessTestHelpers::folder($storage, $node, "level-{$i}");
        $levels[$i] = $node;
    }

    $target = $levels[5];
    $chain = $target->fresh()->ancestors()->get();

    foreach ($chain as $ancestor) {
        expect($ancestor->depth)->toBeLessThan(0);
    }
})->group('verify-f-p4.2-01');
