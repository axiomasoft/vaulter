<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileDenyDriver;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Drive show authorization — `GET v1/drives/{drive}` reads the meta only after View on
 * the drive ROOT node, through the same operations as a PHP caller. This file pins the
 * EXACT gate at the drive seam:
 *
 *   - a rooted drive opens ONLY when the actor holds a View-implying grant on the root;
 *     a missing grant or an administrative block answers 404 drive_not_found, exactly
 *     like an unknown drive (D38), and a guest 401 actor_required;
 *   - an un-ROOTED drive (root_node_id null) has no node to gate on and is a
 *     corrupt/transient state (provisioning is transactional) — it 404s for every
 *     authenticated caller, so the meta never leaks to a grantless actor;
 *   - on an OPEN (null) driver `$access->can` delegates to the driver, so the show is
 *     open for any authenticated actor regardless of grants.
 *
 * Runs on the standalone, grant-authoritative database driver (the same lane as
 * DriveAuthorizationTest / AccessAwareListingTest) — a grant is the only thing that
 * opens a door — except the final open-driver case which flips the driver to 'null'.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-drive.access.permission_driver', 'database');
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
    config()->set('vaulter-drive.access.permission_driver', 'null');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(NodeReadRepository::class);
}

// ── (a) Rooted storage, authenticated actor WITH a View-implying grant on the root → 200 + meta.
it('shows a rooted storage for an actor holding a view grant on the root', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // Reader on the root carries the READ bit ⇒ View is allowed ⇒ the show opens.
    AccessTestHelpers::grant($root, RoleRegistry::READER, scope: GrantScope::Subtree);

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', (string) $storage->getKey())
        ->assertJsonPath('data.root_node_id', (string) $storage->root_node_id);
});

// ── (b) Rooted drive, authenticated actor with NO grant on the root → 404, like an unknown drive.
it('refuses a rooted storage show for an actor with no grant on the root', function (): void {
    $storage = AccessTestHelpers::storage();

    // No grant anywhere ⇒ the View-guard on the root hides the drive.
    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertNotFound()
        ->assertJsonPath('code', 'drive_not_found');
});

// ── (c) An administrative block reaches the storage endpoint: an owner grant on the
//        root overridden by the permission driver's Deny → 404 (the EXACT gate, not
//        just "a grant exists").
it('refuses a rooted storage show when an administrative block overrides the root grant (exact gate)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // The root has no ancestor, so no inheritance boundary can cut it off; the host's
    // administrative block is what still closes it.
    AccessTestHelpers::grant($root, RoleRegistry::OWNER, scope: GrantScope::Subtree);
    config()->set('vaulter-drive.access.permission_drivers.blocked', ProfileDenyDriver::class);
    config()->set('vaulter-drive.access.permission_driver', 'blocked');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertNotFound()
        ->assertJsonPath('code', 'drive_not_found');
});

// ── (d) Rooted drive, UNAUTHENTICATED guest → 401 before anything is looked up.
it('refuses a rooted storage show for an unauthenticated guest', function (): void {
    $storage = AccessTestHelpers::storage();
    // Even an Owner grant for user 1 is irrelevant — THIS request has no actor, so the
    // View-guard on the root denies it (the storage meta must never reach a guest).
    AccessTestHelpers::grant($storage->rootNode()->first(), RoleRegistry::OWNER, scope: GrantScope::Subtree);

    actAsStorageGuest();

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'actor_required');
});

// ── (e) No-root storage (root_node_id null): 404 for EVERYONE. Provisioning is
//        transactional (the root is born with the storage — StorageManager::provision),
//        so a missing root is a corrupt/transient state with no node to authorize on, not
//        a viewable "shell". It must never leak the meta (name/owner/quota) to a grantless
//        actor — matching ActivityController/DocumentNodeController's deny-on-missing-root.
it('404s a no-root storage show for an authenticated grantless actor (no meta leak)', function (): void {
    $storage = AccessTestHelpers::storage();

    // Null the root (a hard-deleted root leaves root_node_id dangling — it is NOT a DB FK).
    // The acting user (id=1) holds NO grant anywhere: a 200 here would be an IDOR leak of
    // the storage owner/name/quota to any authenticated caller.
    app(VaulterDatabase::class)->write(fn () => $storage->forceFill(['root_node_id' => null])->save());

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertStatus(404);
});

it('asks a guest to authenticate before revealing anything about a no-root storage', function (): void {
    $storage = AccessTestHelpers::storage();

    // No root AND no actor: 401 — the meta never reaches an actor-less caller.
    app(VaulterDatabase::class)->write(fn () => $storage->forceFill(['root_node_id' => null])->save());

    actAsStorageGuest();

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertUnauthorized()
        ->assertJsonMissingPath('data');
});

// ── open-driver passthrough: on the OPEN null driver $access->can delegates to the
//    driver, so the storage show is open for any authenticated actor regardless of
//    grants (mirrors AccessAwareListingTest:337's open-listing lock).
it('opens a rooted storage show on the null driver regardless of grants', function (): void {
    $storage = AccessTestHelpers::storage();
    // No grant at all — on the open driver the View-guard delegates to the null driver,
    // which opens for any actor, so the show must still 200 (the database lane would 403).
    useNullPermissionDriver();

    $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', (string) $storage->getKey());
});
