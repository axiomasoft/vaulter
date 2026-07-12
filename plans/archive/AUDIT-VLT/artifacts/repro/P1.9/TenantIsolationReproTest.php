<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\TestCase;

/**
 * AUDIT-VLT P1.9 repro — read-only probes of tenant / cross-storage isolation.
 * NOT part of the package suite (D6): lives under plans/AUDIT-VLT/artifacts/repro.
 *
 * Two storages on two distinct host owners stand in for two tenants. The claim
 * under test: "an actor of one tenant cannot reach another tenant's row." The
 * probes separate the TWO possible barriers — a query-level tenant scope (which,
 * per ADR-0003, the package deliberately does NOT provide on its default models)
 * versus per-action object authorization via the AccessResolver (which is the
 * package's real, sole barrier on the standalone `database` lane).
 *
 * Covers C4 (route-binding / repo find by bare PK), C6 (no tenant context is
 * consumed by the package — default models carry no global scope) and C8
 * (sharedWith does not surface a foreign node).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Standalone lane: only v_node_grants authorise (no host roles).
    config()->set('vaulter.drivers.permission', 'database');
    config()->set('vaulter.features.grants', true);
});

// ── C6: the default Node model carries NO tenant/storage global scope, so a raw
//        find() by primary key returns a FOREIGN-storage row unfiltered. This is
//        the query-level fact: the package does not scope by tenant (ADR-0003).
it('C6: default Node model has no global scope — a bare find() returns a foreign node', function (): void {
    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();

    expect($storageA->id)->not->toBe($storageB->id);
    expect($storageA->owner_id)->not->toBe($storageB->owner_id);

    $foreignNode = AccessTestHelpers::folder($storageB, null, 'b-secret');

    /** @var Node|null $viaModel */
    $viaModel = Node::query()->find($foreignNode->id);

    // No TenantScope / no global scope: the row resolves regardless of any actor.
    expect($viaModel)->not->toBeNull();
    expect($viaModel->id)->toBe($foreignNode->id);

    dump('[C6] Node::find(foreignId) returns the foreign row (no query-level tenant scope): '
        .($viaModel !== null ? 'RESOLVED' : 'NULL'));
});

// ── C4: the route-binding / repository resolve a node by BARE PK (findOrFail /
//        find) with no tenant scope, so per-action object authorization is the
//        ONLY barrier. On the database lane an actor with a grant in storage A is
//        DENIED View on a node in storage B (no grant there).
it('C4: repo find() resolves a foreign node, but AccessResolver::can gates it', function (): void {
    $repo = app(NodeReadRepository::class);
    $access = app(AccessResolver::class);

    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();

    // Actor holds an OWNER grant in storage A only.
    $aNode = AccessTestHelpers::folder($storageA, null, 'a-owned');
    AccessTestHelpers::grant($aNode, GrantLevel::Owner, scope: GrantScope::Subtree, subjectId: 1);

    $bNode = AccessTestHelpers::folder($storageB, null, 'b-owned');

    $actor = AccessTestHelpers::user(1);

    // The repository (mirrors the {node} route-binding findOrFail) resolves the
    // foreign row by bare PK — no scope stops it.
    $resolved = $repo->find($bNode->id);
    expect($resolved)->not->toBeNull();

    // The real barrier: object authorization denies the cross-storage node.
    $canForeign = $access->can($actor, Ability::View, $bNode);
    $canOwn = $access->can($actor, Ability::View, $aNode);

    expect($canOwn)->toBeTrue();
    expect($canForeign)->toBeFalse();

    dump('[C4] repo->find(foreignId)='.($resolved !== null ? 'RESOLVED' : 'NULL')
        .' | can(View,own)='.($canOwn ? 'TRUE' : 'FALSE')
        .' | can(View,foreign)='.($canForeign ? 'TRUE' : 'FALSE'));
});

// ── C8: "shared with me" must not surface a node the actor holds no grant on,
//        even across storages. sharedWith re-resolves each candidate exactly.
it('C8: sharedWith does not surface a foreign-storage node without a grant', function (): void {
    $repo = app(NodeReadRepository::class);

    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();

    // Actor 1 owns a node in A; a foreign node exists in B with NO grant to actor 1.
    $aNode = AccessTestHelpers::folder($storageA, null, 'a-owned');
    AccessTestHelpers::grant($aNode, GrantLevel::Owner, scope: GrantScope::Subtree, subjectId: 1);

    $bNode = AccessTestHelpers::folder($storageB, null, 'b-secret');

    $actor = AccessTestHelpers::user(1);

    $ids = $repo->sharedWith($actor)->pluck('id')->all();

    expect($ids)->toContain($aNode->id);
    expect($ids)->not->toContain($bNode->id);

    dump('[C8] sharedWith ids='.json_encode($ids)
        .' | contains own='.(in_array($aNode->id, $ids, true) ? 'YES' : 'NO')
        .' | contains foreign='.(in_array($bNode->id, $ids, true) ? 'YES' : 'NO'));
});

// ── Cross-check: an actor of "tenant B" (user 2, no grants anywhere) reaches no
//        node at all through the authorized listing seam — fail-closed.
it('C4/C8: an actor with no grants reaches no node via the authorized seam', function (): void {
    $repo = app(NodeReadRepository::class);

    $storageA = AccessTestHelpers::storage();
    $aNode = AccessTestHelpers::folder($storageA, null, 'a-owned');
    AccessTestHelpers::grant($aNode, GrantLevel::Owner, scope: GrantScope::Subtree, subjectId: 1);

    $stranger = AccessTestHelpers::user(2);

    $listing = $repo->queryForUser($stranger)->pluck('id')->all();
    $shared = $repo->sharedWith($stranger)->pluck('id')->all();

    expect($listing)->toBe([]);
    expect($shared)->toBe([]);

    dump('[C4/C8] stranger listing='.json_encode($listing).' shared='.json_encode($shared));
});
