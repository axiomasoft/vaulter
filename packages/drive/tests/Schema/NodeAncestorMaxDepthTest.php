<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * F-P4.2-01 (VLT-REMED P4.3, batch B16): the ancestor-side traversal
 * (AccessResolver::ancestorChain(), the walk EVERY authorization check runs;
 * NodeReadRepository::ancestors()/breadcrumb()) must be bounded by withMaxDepth,
 * symmetrically with descendants()/subtree() (ADR-0005).
 *
 * Red-before: before this item, the recursive CTE staudenmeir compiles for
 * `ancestors()`/`ancestorsAndSelf()` carried NO `depth <` predicate — the walk
 * ran unbounded on every authorization call. The repro this item's finding cites
 * (`plans/AUDIT-VLT/artifacts/repro/P4.2/NPlusOneReproTest.php`, test C1) proved
 * this by asserting the ABSENCE of `depth <` in the captured SQL; this test
 * asserts its PRESENCE, which is exactly the delta the fix introduces.
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
});

it('AccessResolver::can() compiles the ancestor CTE with a withMaxDepth bound (F-P4.2-01)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    AccessTestHelpers::grant($root, RoleRegistry::WRITER);

    // A chain well below DEFAULT_MAX_DEPTH — this only proves the CAP is
    // compiled into the SQL, not that a deep chain is truncated (that would
    // need a fixture deeper than the cap, which is unnecessarily slow here).
    $depth = 10;
    $node = $root;

    for ($i = 0; $i < $depth; $i++) {
        $node = AccessTestHelpers::folder($storage, $node, "level-{$i}");
    }

    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $resolver->can($actor, Ability::View, $node->fresh());

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $ancestorQuery = null;

    foreach ($log as $entry) {
        $sql = (string) $entry['query'];

        if (str_contains($sql, 'with recursive') && str_contains($sql, 'laravel_cte')) {
            $ancestorQuery = $sql;

            break;
        }
    }

    expect($ancestorQuery)->not->toBeNull()
        ->and($ancestorQuery)->toContain(DB::connection()->getQueryGrammar()->wrap('depth').' <');
});

it('NodeReadRepository::ancestors() compiles the ancestor CTE with a withMaxDepth bound (F-P4.2-01)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    $node = AccessTestHelpers::folder($storage, $root, 'child');

    $repository = app(NodeReadRepository::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $repository->ancestors($node->fresh());

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $sql = collect($log)->pluck('query')->implode(' ');

    expect($sql)->toContain(DB::connection()->getQueryGrammar()->wrap('depth').' <');
});

it('NodeReadRepository::breadcrumb() compiles the ancestor CTE with a withMaxDepth bound (F-P4.2-01)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    $node = AccessTestHelpers::folder($storage, $root, 'child');

    $repository = app(NodeReadRepository::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $repository->breadcrumb($node->fresh());

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $sql = collect($log)->pluck('query')->implode(' ');

    expect($sql)->toContain(DB::connection()->getQueryGrammar()->wrap('depth').' <');
});
