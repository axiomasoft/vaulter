<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

/**
 * Skeptic verification for F-P4.2-01 ("AccessResolver::ancestorChain() is not
 * bounded by withMaxDepth, contra ADR-0005").
 *
 * Goal: check whether the ADR-0005 Decision bullet ("withMaxDepth() for shallow
 * loads") was meant to apply to the ACL ancestor walk, or whether the algorithm's
 * OWN documented bound (H.2 — storage_id + inherit_grants=false wall, see the
 * AccessResolver class docblock) is the intentional substitute — and whether
 * naively applying a numeric withMaxDepth() cap (as F-P4.2-01 recommends) can
 * silently break a real grant that sits beyond the cap.
 *
 * This does NOT touch production code (read-only probe).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
});

it('C1: an inherited grant beyond a shallow depth cap is honoured by the current unbounded walk', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    // Build a 5-level chain below the root: L1..L5. Target = L5.
    $node = $root;
    $levels = [];

    for ($i = 1; $i <= 5; $i++) {
        $node = AccessTestHelpers::folder($storage, $node, "level-{$i}");
        $levels[$i] = $node;
    }

    $target = $levels[5];

    // A subtree ALLOW grant sits on L1 — 4 hops away from the target (chain is
    // nearest-first: target=depth0, L4=1, L3=2, L2=3, L1=4).
    AccessTestHelpers::grant($levels[1], GrantLevel::Writer);

    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    // 1) Current (unbounded) behaviour: the grant at depth 4 IS honoured.
    expect($resolver->can($actor, Ability::View, $target->fresh()))->toBeTrue();

    // 2) Simulate what F-P4.2-01's literal recommendation would do: wrap the
    // ancestor call in `Config::nodeModel()::withMaxDepth(DEFAULT_MAX_DEPTH, …)`,
    // the SAME pattern (and the SAME positive constant) NodeReadRepository already
    // uses for descendants()/subtree(). staudenmeir's withMaxDepth() picks its SQL
    // operator from the SIGN of the argument (`$maxDepth > 0 ? '<' : '>'`,
    // vendor/staudenmeir/laravel-adjacency-list/.../HasAdjacencyList.php:469) —
    // but ancestors carry NEGATIVE depth (-1, -2, …), so a POSITIVE cap compiles
    // to `depth < 64`, which is trivially true for every negative depth and
    // constrains NOTHING. The literal recommended fix is a no-op on the ancestor
    // side.
    $freshTarget = $target->fresh();
    $model = config('vaulter.models.node');
    $noopCappedChain = $model::withMaxDepth(64, static fn () => $freshTarget->ancestors()->get());
    expect($noopCappedChain->pluck('id')->all())->toContain($levels[1]->id);

    // 3) The TECHNICALLY CORRECT cap for ancestors needs a NEGATIVE argument
    // (`depth > -N`). Applying it with N=2 (fewer hops than the 4 separating the
    // target from L1) DOES drop the grant-bearing ancestor — proving that any cap
    // small enough to bound cost is also small enough to silently break a real,
    // legitimate grant on a tree no deeper than the cap.
    $correctlyCappedChain = $model::withMaxDepth(-2, static fn () => $freshTarget->ancestors()->get());
    $cappedIds = $correctlyCappedChain->pluck('id')->all();

    expect($cappedIds)->not->toContain($levels[1]->id);

    // 3) Confirm the FULL (uncapped) walk the resolver actually performs DOES
    // reach L1 — this is the "cost" ADR-0005's Consequences section names
    // explicitly ("hot ACL resolution … CTE") and defers to an opt-in
    // ltree/closure-table read-model, not to a query-level depth cap.
    $fullChain = $target->fresh()->ancestors()->get();
    $fullIds = $fullChain->pluck('id')->all();

    expect($fullIds)->toContain($levels[1]->id);
})->group('verify-f-p4.2-01');

it('C2: the resolver bound is inherit_grants=false / storage boundary, not a numeric depth', function (): void {
    // A wall (inherit_grants=false) 6 levels up, with the only relevant grant
    // sitting AT the wall itself. A depth cap smaller than 6 would silently drop
    // the wall node from the fetched chain, which — per the algorithm's own
    // documented semantics (AccessResolver class docblock, H.2 step 2/3) — is a
    // DIFFERENT bounding mechanism than a numeric withMaxDepth ceiling.
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    // L1 (closest to root) is the wall; L2..L6 inherit normally. target = L6, the
    // deepest node — its ancestor walk must cross L5..L2 before reaching the wall
    // L1, five hops away.
    $node = $root;
    $levels = [];

    for ($i = 1; $i <= 6; $i++) {
        $inheritGrants = $i !== 1; // L1 is the wall (nearest to root)
        $node = AccessTestHelpers::folder($storage, $node, "wall-{$i}", $inheritGrants);
        $levels[$i] = $node;
    }

    $target = $levels[6];

    // The wall's OWN subtree grant is what the target inherits (H.2: a wall's own
    // grants count; the break happens AFTER consuming them).
    AccessTestHelpers::grant($levels[1], GrantLevel::Reader);

    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);

    // The wall's own ALLOW grant is honoured by the current full-chain walk.
    expect($resolver->can($actor, Ability::View, $target->fresh()))->toBeTrue();

    fwrite(STDERR, "\n[verify-F-P4.2-01] confirmed: correctness of the current ACL algorithm depends on reaching the inherit_grants=false wall regardless of its numeric depth — a fixed withMaxDepth() ceiling smaller than the wall's depth would silently flip this from allow to deny.\n");
})->group('verify-f-p4.2-01');
