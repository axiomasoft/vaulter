<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;

/*
 * Standalone baseline: tenancy is disabled by default, so low-level model queries
 * and the default Node model retain their original behavior. Tenant isolation
 * when enabled is enforced by TenancyBoundary at product entry points and owning
 * drive query predicates; host model scopes are additional protection.
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
    AccessTestHelpers::grant($nodeA, RoleRegistry::READER, scope: GrantScope::Item);
    // Deliberately NO grant for $actor anywhere in storageB.

    $access = app(AccessResolver::class);

    expect($access->can($actor, Ability::View, $nodeA))->toBeTrue()
        ->and($access->can($actor, Ability::View, $nodeB))->toBeFalse();

    $sharedIds = app(NodeReadRepository::class)->sharedWith($actor)->pluck('id')->all();

    expect($sharedIds)->toContain($nodeA->getKey())
        ->and($sharedIds)->not->toContain($nodeB->getKey());
});

it('carries no TENANT global scope on the default Node model (the barrier is per-action, not query-level, F-P1.9-02)', function (): void {
    // The standalone baseline does not add a TENANT global scope on its
    // default models (one would break GC/reconcile/sweeper, which must see EVERY
    // tenant). Node carries no global scope at all (purge is a real DELETE, so not
    // even SoftDeletingScope), so this fails the moment a tenant/owner scope is
    // registered, before any isolation symptom would even be reachable.
    expect(array_keys((new Node)->getGlobalScopes()))->toBe([]);
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
