<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;

/*
 * F-P1.9-02 (Minor) — a contract test pinning the load-bearing invariant of
 * ADR-0003 ("Vaulter does not scope by tenant — the host's swapped model applies
 * its own TenantScope"): the DEFAULT models carry NO query-level tenant/owner
 * filtering, and the sole barrier is per-action AccessResolver authorization
 * (H.2). Before this test the contract lived only in ADR-0003 + docblocks
 * (P1.9 §F-P1.9-02 "no test proves it") — a future refactor could silently add a
 * global scope (breaking GC/reconcile/sweeper, which must see every tenant) or
 * silently drop the per-action gate (opening cross-owner access) and nothing
 * would catch it. Green/coverage-gap form (D19 — no behaviour delta, the
 * behaviour under test is already correct; the gap was the missing assertion).
 *
 * Two separately-owned storages stand in for two tenants: the default models
 * only populate tenant_id through a host-supplied tenant-aware model swap
 * (P1.9 §"Не проверено"), so "two owners, cross-owner access" is the honest
 * in-package proxy for "two tenants, cross-tenant access" — the SAME proxy the
 * P1.9 repro (`TenantIsolationReproTest`) uses.
 */

it('never lets an actor granted in one storage reach a node in another via can() or sharedWith, F-P1.9-02', function (): void {
    // The grant-authoritative lane: v_node_grants is the sole authority for these
    // assertions. The suite default ('null' driver) is intentionally OPEN — it
    // would trivially pass can()=true for both nodes and defeat the point of this
    // contract test.
    config()->set('vaulter.access.permission_driver', 'database');

    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();

    $nodeA = AccessTestHelpers::folder($storageA, null, 'in-a');
    $nodeB = AccessTestHelpers::folder($storageB, null, 'in-b');

    $actor = AccessTestHelpers::user();
    AccessTestHelpers::grant($nodeA, GrantLevel::Reader, scope: GrantScope::Item);
    // Deliberately NO grant for $actor anywhere in storageB.

    $access = app(AccessResolver::class);

    expect($access->can($actor, Ability::View, $nodeA))->toBeTrue()
        ->and($access->can($actor, Ability::View, $nodeB))->toBeFalse();

    $sharedIds = app(NodeReadRepository::class)->sharedWith($actor)->pluck('id')->all();

    expect($sharedIds)->toContain($nodeA->getKey())
        ->and($sharedIds)->not->toContain($nodeB->getKey());
});

it('carries no TENANT global scope on the default Node model (the barrier is per-action, not query-level, F-P1.9-02)', function (): void {
    // ADR-0003 is explicit: the package does not add a TENANT global scope on its
    // default models (one would break GC/reconcile/sweeper, which must see EVERY
    // tenant). Node DOES carry Laravel's own SoftDeletingScope (framework
    // infrastructure, unrelated to tenancy) — this asserts no ADDITIONAL scope
    // rides along with it, so this fails the moment a tenant/owner scope is
    // registered, before any isolation symptom would even be reachable.
    expect(array_keys((new Node)->getGlobalScopes()))
        ->toBe([SoftDeletingScope::class]);
});

it('resolves a node by bare key with zero tenant/owner predicate applied (delegated tenancy, F-P1.9-02)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'bare-key');

    // No actor, no grant context at all — a bare PK lookup still resolves the
    // row, proving the package itself applies no isolating predicate (isolation
    // is the caller's/host's job, enforced at the AUTHORIZATION layer instead).
    $found = app(NodeReadRepository::class)->find($node->getKey());

    expect($found)->not->toBeNull()
        ->and($found->getKey())->toBe($node->getKey());
});
