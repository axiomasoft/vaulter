<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

/**
 * AUDIT-VLT F-P4.2-03 skeptic verification repro. Copy of
 * plans/AUDIT-VLT/artifacts/repro/P4.2/NPlusOneReproTest.php with
 * GrantLevel::Writer (does not exist) fixed to GrantLevel::Writer, run after
 * clearing the stale published-migration pollution left in
 * vendor/orchestra/testbench-core/laravel/database/migrations/ by an unrelated
 * P2.2 repro (that pollution, not this finding, caused every suite run to fail
 * with "table v_storages already exists" — see
 * plans/AUDIT-VLT/artifacts/repro/verify-F-P2.2-02/NOTES.md).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
});

// ── C4: abilities() (what NodeResource calls per listed row) issues a query per
// node instead of an amortised eager-loaded batch — queries scale with page size.
it('C4: resolving abilities for N sibling nodes issues O(N) queries, not O(1)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = \Vaulter\Models\Drive\Node::find($storage->root_node_id);

    // A grant on the root so the subtree has something to resolve (mirrors a real
    // listing where the actor has access through inheritance).
    AccessTestHelpers::grant($root, GrantLevel::Writer);

    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    $countQueriesFor = function (int $n) use ($storage, $root, $actor, $resolver): int {
        $children = [];

        for ($i = 0; $i < $n; $i++) {
            $children[] = AccessTestHelpers::folder($storage, $root, "child-{$n}-{$i}");
        }

        // Simulate exactly what NodeResource::abilities() does per row of a page
        // (packages/core/src/Http/Resources/Drive/NodeResource.php:138-148): one
        // AccessResolver call per node, no batching, no eager-loaded storage/grants.
        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($children as $child) {
            $resolver->abilitiesFor($actor, $child->fresh());
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count($log);
    };

    $queriesFor5 = $countQueriesFor(5);
    $queriesFor20 = $countQueriesFor(20);

    // A constant-cost (eager-loaded / batched) implementation would not scale
    // query count linearly with the number of resolved nodes. Print the facts a
    // finding needs verbatim.
    fwrite(STDERR, sprintf(
        "\n[P4.2 C4] queries for 5 nodes = %d, queries for 20 nodes = %d (ratio %.2f)\n",
        $queriesFor5,
        $queriesFor20,
        $queriesFor5 > 0 ? $queriesFor20 / $queriesFor5 : 0.0,
    ));

    expect($queriesFor20)->toBeGreaterThan($queriesFor5);
})->group('p4.2');

// ── C1: the ancestor walk AccessResolver::ancestorChain() uses (the walk on EVERY
// authorization call) is not bounded by withMaxDepth — it materialises the whole
// chain up to the storage root regardless of depth.
it('C1: the AccessResolver ancestor walk has no withMaxDepth cap and fetches the full chain', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = \Vaulter\Models\Drive\Node::find($storage->root_node_id);

    AccessTestHelpers::grant($root, GrantLevel::Writer);

    // Build a chain of 30 nested folders below the root.
    $depth = 30;
    $node = $root;

    for ($i = 0; $i < $depth; $i++) {
        $node = AccessTestHelpers::folder($storage, $node, "level-{$i}");
    }

    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $resolver->can($actor, \Vaulter\Enums\Access\Ability::View, $node->fresh());

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Find the recursive CTE query issued for the ancestor walk and assert it
    // carries no depth-limiting predicate (no "depth <" / "depth <=" fragment,
    // which is how staudenmeir's withMaxDepth bounds the CTE).
    $ancestorQuery = null;

    foreach ($log as $entry) {
        $sql = (string) $entry['query'];

        if (str_contains($sql, 'with recursive') && str_contains($sql, 'laravel_cte')) {
            $ancestorQuery = $sql;

            break;
        }
    }

    fwrite(STDERR, sprintf(
        "\n[P4.2 C1] ancestor-walk SQL (depth=%d chain): %s\n",
        $depth,
        $ancestorQuery ?? '(no recursive CTE query captured — see full log)',
    ));

    if ($ancestorQuery === null) {
        fwrite(STDERR, "[P4.2 C1] full query log:\n");

        foreach ($log as $entry) {
            fwrite(STDERR, '  '.$entry['query']."\n");
        }
    }

    expect($ancestorQuery)->not->toBeNull();
    // withMaxDepth compiles a depth predicate into the CTE's recursive leg
    // (e.g. "depth <"); its absence confirms the walk is unbounded.
    expect($ancestorQuery)->not->toContain('depth <');
})->group('p4.2');
