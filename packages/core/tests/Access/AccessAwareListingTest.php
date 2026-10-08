<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\RoleSubjectGrantDriver;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Repository candidate prefilters and exact HTTP visibility are separate seams.
 * HTTP listings evaluate candidate pages through the batch ACL resolver and expose
 * a signed cursor without totals, including for the cross-storage shared endpoint.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    // The standalone, grant-authoritative lane: v_node_grants is the sole authority,
    // so the listing may narrow to the candidate set.
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

/**
 * Swap the active host-role driver to a fixture instance: bind it under a 'stub' key,
 * point config at it, and drop the memoised manager so the next resolve picks it up.
 */
function useListingDriver(object $driver): void
{
    app()->instance($driver::class, $driver);
    config()->set('vaulter.access.permission_drivers.stub', $driver::class);
    config()->set('vaulter.access.permission_driver', 'stub');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(NodeReadRepository::class);
}

function nodeRepo(): NodeReadRepository
{
    return app(NodeReadRepository::class);
}

/**
 * Drop the acting user the beforeEach set, so the next HTTP request resolves
 * $request->user() === null (a genuine guest) — the no-actor path the controller /
 * resource guard against.
 */
function actAsGuest(): void
{
    auth()->guard()->forgetUser();
    auth()->forgetGuards();
}

// ── 1. index = the candidate set: subtree-anchor descendants in, an un-granted sibling out.
it('index lists the subtree-anchor descendants and hides an un-granted sibling', function (): void {
    $storage = AccessTestHelpers::storage();

    // A subtree grant on the ANCHOR folder (no direct grant on its children) reaches
    // every descendant; the SIBLING tree has no grant at all. An ITEM grant on the
    // root makes the root itself viewable (so the index View-guard on the parent passes)
    // WITHOUT seeding the root's whole subtree as candidates — only the anchor lane is.
    $root = $storage->rootNode()->first();
    $anchor = AccessTestHelpers::folder($storage, null, 'anchor');
    $child = AccessTestHelpers::folder($storage, $anchor, 'child');
    $sibling = AccessTestHelpers::folder($storage, null, 'sibling');

    AccessTestHelpers::grant($root, RoleRegistry::READER, scope: GrantScope::Item);
    AccessTestHelpers::grant($anchor, RoleRegistry::READER, scope: GrantScope::Subtree);

    // Listing the anchor's children surfaces the descendant (reached via the ancestor
    // subtree anchor, no direct grant of its own).
    $this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes?parent_id='.$anchor->getKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $child->getKey());

    // Listing the ROOT surfaces the anchor (a candidate), but NOT the un-granted sibling.
    $rootIds = collect($this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes')->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($rootIds)->toContain($anchor->getKey())
        ->and($rootIds)->not->toContain($sibling->getKey());
});

// ── 2. an item-scoped anchor yields zero candidate children; the folder itself still opens.
it('lists zero children for an item-scoped folder grant while the folder itself still views', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'item-only');
    $child = AccessTestHelpers::folder($storage, $folder, 'inside');

    // ITEM scope = that node ONLY: the folder is a candidate, its child is not.
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    // The folder's children listing is EMPTY (the child is no candidate)…
    $this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes?parent_id='.$folder->getKey())
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // …yet View/show of the folder ITSELF works (it is the item-scoped candidate).
    $this->getJson('api/vaulter/nodes/'.$folder->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $folder->getKey());

    expect($child)->not->toBeNull(); // (the child exists; it is simply not a candidate)
});

// ── 3. GET shared: cross-storage allow-grants both appear.
it('returns cross-storage allow-grants in the shared view', function (): void {
    $storageA = AccessTestHelpers::storage();
    $folderA = AccessTestHelpers::folder($storageA, null, 'in-a');
    AccessTestHelpers::grant($folderA, RoleRegistry::READER, scope: GrantScope::Item);

    $storageB = AccessTestHelpers::storage();
    $folderB = AccessTestHelpers::folder($storageB, null, 'in-b');
    AccessTestHelpers::grant($folderB, RoleRegistry::READER, scope: GrantScope::Item);

    $ids = collect($this->getJson('api/vaulter/shared')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($folderA->getKey())
        ->and($ids)->toContain($folderB->getKey());
});

// ── 4. GET shared is EXACT: an expired grant is excluded; a direct grant behind an
// inheritance boundary still counts (the boundary only cuts grants from above).
it('excludes an expired grant and keeps a direct grant behind a boundary in the shared view (exact)', function (): void {
    $storage = AccessTestHelpers::storage();

    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child', inheritGrants: false);
    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Subtree);
    AccessTestHelpers::grant($child, RoleRegistry::PREVIEWER, scope: GrantScope::Item);

    // An EXPIRED allow never reaches the shared view (read-time expiry).
    $expired = AccessTestHelpers::folder($storage, null, 'expired');
    AccessTestHelpers::grant(
        $expired,
        RoleRegistry::READER,
        scope: GrantScope::Item,
        expiresAt: Carbon::now()->subHour(),
    );

    $ids = collect($this->getJson('api/vaulter/shared')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($parent->getKey())
        ->and($ids)->toContain($child->getKey())
        ->and($ids)->not->toContain($expired->getKey());
});

// ── 5. GET shared requires an actor and never returns granted data to a guest.
it('requires an actor for an unauthenticated shared request without leaking a granted node', function (): void {
    // A grant exists for user 1, but THIS request has no actor — so the shared view
    // receives actor_required401 and cannot see the granted node.
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant(
        AccessTestHelpers::folder($storage, null, 'secret'),
        RoleRegistry::READER,
        scope: GrantScope::Item,
    );

    actAsGuest();

    $this->getJson('api/vaulter/shared')->assertUnauthorized()->assertJsonMissingPath('data');
});

it('wraps the shared view in an exact cursor envelope', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'shared-one');
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $this->getJson('api/vaulter/shared')
        ->assertOk()
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonMissingPath('meta.total')
        ->assertJsonCount(1, 'data');
});

it('GET shared advertises a cursor envelope without offset totals', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'offset-contract');
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $response = $this->getJson('api/vaulter/shared')->assertOk();

    expect($response->json('meta'))
        ->toHaveKeys(['next_cursor', 'prev_cursor', 'per_page', 'scanned'])
        ->not->toHaveKey('total')
        ->not->toHaveKey('current_page');
});

// ── 6. The public repository seam: queryForUser + constrainToAccessible.
it('constrains an item anchor to only its own node via the repository seam', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'item');
    AccessTestHelpers::folder($storage, $folder, 'descendant'); // not a candidate (item scope)
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $ids = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();

    expect($ids)->toBe([$folder->getKey()]);
});

it('constrains a subtree anchor to the anchor plus all its descendants', function (): void {
    $storage = AccessTestHelpers::storage();
    $anchor = AccessTestHelpers::folder($storage, null, 'anchor');
    $childA = AccessTestHelpers::folder($storage, $anchor, 'a');
    $grandchild = AccessTestHelpers::folder($storage, $childA, 'a-1');
    $outside = AccessTestHelpers::folder($storage, null, 'outside');

    AccessTestHelpers::grant($anchor, RoleRegistry::READER, scope: GrantScope::Subtree);

    $ids = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();

    expect($ids)->toContain($anchor->getKey(), $childA->getKey(), $grandchild->getKey())
        ->and($ids)->not->toContain($outside->getKey())
        ->and($ids)->toHaveCount(3);
});

it('forces an empty result (not the whole table) when the actor reaches no candidate', function (): void {
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::folder($storage, null, 'unreachable'); // exists, but no grant

    // constrainToAccessible on a raw live-nodes base → empty, NOT the full table.
    $base = Node::query()->whereNull('trashed_at');
    $ids = nodeRepo()->constrainToAccessible($base, AccessTestHelpers::user())->pluck('id')->all();

    expect($ids)->toBe([]);
});

// ── 7. Subject breadth: a grant addressed to the actor's ROLE subject is included.
it('includes a grant addressed to the actor role subject in the candidate set', function (): void {
    $storage = AccessTestHelpers::storage();
    $roleFolder = AccessTestHelpers::folder($storage, null, 'role-granted');
    $userFolder = AccessTestHelpers::folder($storage, null, 'user-granted');

    // A grant addressed to the ROLE subject (type 'role', id 7) — NOT the user directly.
    AccessTestHelpers::grant(
        $roleFolder,
        RoleRegistry::READER,
        scope: GrantScope::Item,
        subjectId: 7,
        subjectType: 'role',
    );
    // A control grant addressed to the user directly.
    AccessTestHelpers::grant($userFolder, RoleRegistry::READER, scope: GrantScope::Item);

    // The driver expands the actor into [user, role:7] — both grants are candidates.
    useListingDriver(new RoleSubjectGrantDriver(roleType: 'role', roleId: 7));

    $ids = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();

    expect($ids)->toContain($roleFolder->getKey())
        ->and($ids)->toContain($userFolder->getKey());
});

// ── 9. Per-node ACTION gating stays EXACT even though the node lists (candidate superset).
it('refuses the action on a node behind an inheritance boundary that still appears in the candidate listing', function (): void {
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'subtree');
    $child = AccessTestHelpers::folder($storage, $parent, 'walled', inheritGrants: false);

    // A subtree grant on the parent makes BOTH parent and child candidates (the listing
    // is a superset); the boundary on the child cuts its exact access.
    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Subtree);

    // The child IS in the candidate listing (superset — the boundary is not applied in SQL).
    $listed = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();
    expect($listed)->toContain($child->getKey());

    // …but the per-ACTION gate is EXACT: show on the walled child is a 404.
    $this->getJson('api/vaulter/nodes/'.$child->getKey())->assertNotFound();

    // The parent still shows (sanity — only the walled node is gated).
    $this->getJson('api/vaulter/nodes/'.$parent->getKey())->assertOk();
});

// ── 10. DriveResource.abilities mirror the resolver (view-only, create gate, null actor).
it('exposes view-only storage abilities for a reader on the root', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // Reader on the root: view true, create_folder/upload/manage false.
    AccessTestHelpers::grant($root, RoleRegistry::READER, scope: GrantScope::Subtree);

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.create_folder', false)
        ->assertJsonPath('data.abilities.upload', false)
        ->assertJsonPath('data.abilities.manage', false);
});

it('refuses the storage show for an unauthenticated request on a rooted storage', function (): void {
    $storage = AccessTestHelpers::storage();
    // An Owner grant for user 1 WOULD flip every flag true — but THIS request has no
    // actor, so the controller's View-guard on the rooted storage's root refuses it
    // (no actor ⇒ no grant ⇒ 403). The storage meta must never reach a guest, so there
    // is no `data` body to inspect — the all-false-abilities CONTRACT for a null actor
    // is pinned at the Resource layer below (it can no longer be reached through the
    // guarded controller on a rooted storage).
    AccessTestHelpers::grant($storage->rootNode()->first(), RoleRegistry::OWNER, scope: GrantScope::Subtree);

    actAsGuest();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertStatus(403);
});

// ── 10b. The all-false-abilities CONTRACT for a NULL actor, re-expressed at the layer
// that still reaches it: DriveResource resolves every flag false when the request has
// no acting user, regardless of an Owner grant on the root. (The controller guard now
// 403s a guest on a rooted storage BEFORE the Resource renders — see the test above —
// so this contract lives at the Resource seam, not behind the controller.)
it('renders all-false storage abilities for a null actor at the Resource layer', function (): void {
    $storage = AccessTestHelpers::storage();
    // An Owner grant for user 1 WOULD flip every flag true for THAT actor — but a request
    // with no user must yield the read-only-shell default (all false), the SAFE default.
    AccessTestHelpers::grant($storage->rootNode()->first(), RoleRegistry::OWNER, scope: GrantScope::Subtree);

    // A bare request resolves $request->user() === null — the no-actor path.
    $abilities = DriveResource::make($storage->refresh())->toArray(Request::create('/'))['abilities'];

    expect($abilities)->toBe([
        'view' => false,
        'create_folder' => false,
        'upload' => false,
        'manage' => false,
    ]);
});

// ── 11. Driver-aware gate: a non-GrantAuthoritativeDriver keeps the listing OPEN.
it('keeps grantFilteringApplies true on the database driver and false on the null driver', function (): void {
    // database driver carries the GrantAuthoritativeDriver marker ⇒ filtering applies.
    expect(nodeRepo()->grantFilteringApplies())->toBeTrue();

    // The null driver does NOT carry the marker ⇒ filtering does not apply.
    config()->set('vaulter.access.permission_driver', 'null');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(NodeReadRepository::class);

    expect(nodeRepo()->grantFilteringApplies())->toBeFalse();
});

it('keeps the listing OPEN (every node) on the null driver', function (): void {
    $storage = AccessTestHelpers::storage();
    $a = AccessTestHelpers::folder($storage, null, 'a');
    $b = AccessTestHelpers::folder($storage, null, 'b');
    // No grants at all — on the open driver the listing must still return every node.

    config()->set('vaulter.access.permission_driver', 'null');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(NodeReadRepository::class);

    // queryForUser is a no-op on the open driver: the un-granted nodes are all present.
    $ids = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();

    expect($ids)->toContain($a->getKey())
        ->and($ids)->toContain($b->getKey());

    // The HTTP children listing is also open — both root folders list with no grant.
    $rootIds = collect(
        $this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes')->assertOk()->json('data'),
    )->pluck('id')->all();

    expect($rootIds)->toContain($a->getKey(), $b->getKey());
});

// ── 12. Scale/chunking smoke: a modest fan-out lists complete through whereIn chunking.
it('lists a whole modest subtree fan-out through the chunked whereIn path', function (): void {
    $storage = AccessTestHelpers::storage();
    $anchor = AccessTestHelpers::folder($storage, null, 'anchor');

    $expected = [$anchor->getKey()];

    // ~30 descendants under the subtree anchor — proves the chunked whereIn descent is
    // complete without building a pathological (>999) tree.
    for ($i = 0; $i < 30; $i++) {
        $expected[] = AccessTestHelpers::folder($storage, $anchor, 'leaf-'.$i)->getKey();
    }

    AccessTestHelpers::grant($anchor, RoleRegistry::READER, scope: GrantScope::Subtree);

    $ids = nodeRepo()->queryForUser(AccessTestHelpers::user())->pluck('id')->all();

    sort($ids);
    sort($expected);

    expect($ids)->toBe($expected);
});

// ── sharedWith repository return type is a bounded collection (envelope plumbing).
it('returns the shared view as a paginated envelope from the controller', function (): void {
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant(
        AccessTestHelpers::folder($storage, null, 'one'),
        RoleRegistry::READER,
        scope: GrantScope::Item,
    );

    $this->getJson('api/vaulter/shared')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'per_page', 'scanned']]);
});
