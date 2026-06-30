<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Storage SHOW authorization (M2a / H.2) — `GET storages/{storage}` now guards
 * Ability::View on the storage ROOT node through the AccessResolver before exposing
 * the meta, exactly as NodeController guards a per-node show. This file pins the
 * EXACT gate at the storage seam:
 *
 *   - a rooted storage opens ONLY when the actor holds a View-implying grant on the
 *     root; a missing grant, a deny-veto on the root, and a guest all 403;
 *   - an un-ROOTED storage (root_node_id null) has no node to gate on — an
 *     AUTHENTICATED actor opens the read-only shell (all-false abilities), but a
 *     guest is still denied (the storage meta must never reach an actor-less caller);
 *   - on an OPEN (null) driver `$access->can` delegates to the driver, so the show is
 *     open for any authenticated actor regardless of grants.
 *
 * Runs on the standalone, grant-authoritative database driver (the same lane as
 * DriveAuthorizationTest / AccessAwareListingTest) — a grant is the only thing that
 * opens a door — except the final open-driver case which flips the driver to 'null'.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.drivers.permission', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

/**
 * Drop the acting user the beforeEach set, so the next HTTP request resolves
 * $request->user() === null (a genuine guest) — the no-actor path the controller
 * guards against. Mirrors AccessAwareListingTest::actAsGuest().
 */
function actAsStorageGuest(): void
{
    auth()->guard()->forgetUser();
    auth()->forgetGuards();
}

/**
 * Flip the active permission driver to the OPEN null driver for the current test
 * (non-prod, ungated by grants) and drop the memoised manager + repository so the
 * next resolve picks it up. Mirrors AccessAwareListingTest's null-driver swap.
 */
function useNullPermissionDriver(): void
{
    config()->set('vaulter.drivers.permission', 'null');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(NodeReadRepository::class);
}

// ── (a) Rooted storage, authenticated actor WITH a View-implying grant on the root → 200 + meta.
it('shows a rooted storage for an actor holding a view grant on the root', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // Reader on the root carries the READ bit ⇒ View is allowed ⇒ the show opens.
    AccessTestHelpers::grant($root, GrantLevel::Reader, scope: GrantScope::Subtree);

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $storage->getKey())
        ->assertJsonPath('data.root_node_id', $storage->root_node_id)
        ->assertJsonPath('data.abilities.view', true);
});

// ── (b) Rooted storage, authenticated actor with NO grant on the root → 403.
it('refuses a rooted storage show for an actor with no grant on the root', function (): void {
    $storage = AccessTestHelpers::storage();

    // No grant anywhere ⇒ the View-guard on the root denies the actor (no grant).
    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertStatus(403);
});

// ── (c) Deny-veto reaches the storage endpoint: an allow on the root vetoed by a
//        nearer/same-node deny on the root → 403 (the EXACT gate, not just presence).
it('refuses a rooted storage show when a deny vetoes the root view (exact gate)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // The storage root is the TOP of the tree (parent_id null) — it has no in-storage
    // ancestor, so the only allow that flows to it is its own subtree grant. An Owner
    // subtree allow on the root WOULD open View; an explicit Item DENY on the SAME root
    // vetoes it (a same-node deny is an absolute veto — AccessResolver step 5, mirroring
    // AccessAwareListingTest:271/:279 where a subtree-allow ancestor is overridden by a
    // nearer deny). This proves the resolver's exact deny-veto reaches the storage show,
    // not merely "a grant exists".
    AccessTestHelpers::grant($root, GrantLevel::Owner, scope: GrantScope::Subtree);
    AccessTestHelpers::grant($root, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertStatus(403);
});

// ── (d) Rooted storage, UNAUTHENTICATED guest → 403 (no actor ⇒ no grant on the root).
it('refuses a rooted storage show for an unauthenticated guest', function (): void {
    $storage = AccessTestHelpers::storage();
    // Even an Owner grant for user 1 is irrelevant — THIS request has no actor, so the
    // View-guard on the root denies it (the storage meta must never reach a guest).
    AccessTestHelpers::grant($storage->rootNode()->first(), GrantLevel::Owner, scope: GrantScope::Subtree);

    actAsStorageGuest();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertStatus(403);
});

// ── (e) No-root storage (root_node_id null): authenticated actor → 200 + all-false
//        abilities; unauthenticated guest → 403 (the deny-without-actor decision).
it('opens a no-root storage for an authenticated actor with all-false abilities', function (): void {
    $storage = AccessTestHelpers::storage();

    // Null the root: a freshly provisioned-but-unrooted shell. There is no node to gate
    // on, so an AUTHENTICATED actor opens it; the Resource returns all-false abilities
    // (no root ⇒ the read-only-shell default).
    $storage->forceFill(['root_node_id' => null])->save();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $storage->getKey())
        ->assertJsonPath('data.root_node_id', null)
        ->assertJsonPath('data.abilities.view', false)
        ->assertJsonPath('data.abilities.create_folder', false)
        ->assertJsonPath('data.abilities.upload', false)
        ->assertJsonPath('data.abilities.manage', false);
});

it('refuses a no-root storage show for an unauthenticated guest', function (): void {
    $storage = AccessTestHelpers::storage();

    // No root AND no actor: the controller denies BEFORE exposing the meta — the storage
    // name/owner/quota must never reach an actor-less caller (deny-on-missing-root).
    $storage->forceFill(['root_node_id' => null])->save();

    actAsStorageGuest();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertStatus(403);
});

// ── open-driver passthrough: on the OPEN null driver $access->can delegates to the
//    driver, so the storage show is open for any authenticated actor regardless of
//    grants (mirrors AccessAwareListingTest:337's open-listing lock).
it('opens a rooted storage show on the null driver regardless of grants', function (): void {
    $storage = AccessTestHelpers::storage();
    // No grant at all — on the open driver the View-guard delegates to the null driver,
    // which opens for any actor, so the show must still 200 (the database lane would 403).
    useNullPermissionDriver();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $storage->getKey());
});
