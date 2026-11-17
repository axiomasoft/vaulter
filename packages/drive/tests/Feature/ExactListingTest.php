<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Vaulter\Drive\Access\ExactListing;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Queries\SharedWithActorQuery;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileDenyDriver;
use Vaulter\Drive\Vaulter;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->actor = AccessTestHelpers::user();
    $this->actingAs($this->actor);
    $this->storage = AccessTestHelpers::storage();
    $this->folder = AccessTestHelpers::folder($this->storage, null, 'parent');
    AccessTestHelpers::grant($this->folder, RoleRegistry::READER, scope: GrantScope::Subtree);
    $this->base = 'api/vaulter/v1/drives/'.$this->storage->id;
});

it('V09 reaches the 301st candidate across empty pages and hides walled-off nodes everywhere', function (): void {
    config()->set('vaulter-drive.listing.scan_budget', 100);
    $hidden = [];
    for ($i = 0; $i < 300; $i++) {
        // Each candidate restricts inheritance, so the parent's subtree grant stops there.
        $child = AccessTestHelpers::folder($this->storage, $this->folder, sprintf('candidate-%04d', $i), inheritGrants: false);
        $hidden[] = $child->id;
    }
    $visible = AccessTestHelpers::folder($this->storage, $this->folder, 'candidate-0300');
    $cursor = null;
    $found = [];
    $emptyPages = 0;
    do {
        $url = $this->base.'/nodes?'.http_build_query(['parent' => (string) $this->folder->id, 'per_page' => 50, 'cursor' => $cursor]);
        $response = $this->getJson($url)->assertOk()->assertJsonMissingPath('meta.total');
        $data = $response->json('data');
        $cursor = $response->json('meta.next_cursor');
        if ($data === []) {
            $emptyPages++;
        }
        $found = array_merge($found, array_column($data, 'id'));
        expect($emptyPages)->toBeLessThan(10);
    } while ($cursor !== null);
    expect($emptyPages)->toBe(3)->and($found)->toBe([(string) $visible->id]);
    // include=children is the first page only (no full walk): here an empty page that
    // still carries a cursor, and never a walled-off child.
    $children = $this->getJson('api/vaulter/v1/nodes/'.$this->folder->id.'?include=children')->assertOk()->json('data.children');
    expect(array_intersect(array_column($children['data'], 'id'), array_map(strval(...), $hidden)))->toBe([])
        ->and($children['meta']['next_cursor'])->not->toBeNull();
    config()->set('vaulter-drive.listing.scan_budget', 1000);
    $this->getJson($this->base.'/search?q=candidate')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $visible->id)->assertJsonMissingPath('meta.total');
    Node::query()->whereIn('id', [...$hidden, $visible->id])->update(['trashed_at' => now(), 'trash_root_id' => DB::raw('id')]);
    $this->getJson($this->base.'/trash')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $visible->id)->assertJsonMissingPath('meta.total');
});

it('V22 keeps a drive-wide Gate role through an inheritance boundary, which only cuts grants', function (): void {
    // A boundary is a grant-inheritance rule, not a block: the host role lane is
    // node-blind and still applies; only an override Deny closes a drive to a role.
    $walled = AccessTestHelpers::folder($this->storage, $this->folder, 'walled', inheritGrants: false);
    $visible = AccessTestHelpers::folder($this->storage, $this->folder, 'visible');
    $this->getJson($this->base.'/nodes?parent='.$this->folder->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $visible->id);

    config()->set('vaulter-drive.access.permission_driver', 'gate');
    Gate::define('vaulter.view', fn (): bool => true);
    $ids = array_column($this->getJson($this->base.'/nodes?parent='.$this->folder->id)->assertOk()->json('data'), 'id');
    expect($ids)->toContain((string) $walled->id)->toContain((string) $visible->id);

    Gate::define('vaulter.super', fn (): bool => false);
    config()->set('vaulter-drive.access.permission_drivers.blocked', ProfileDenyDriver::class);
    config()->set('vaulter-drive.access.permission_driver', 'blocked');
    app()->forgetInstance(PermissionDriverManager::class);
    $this->getJson('api/vaulter/v1/nodes/'.$walled->id)->assertNotFound();
});

it('uses at most five Vaulter queries for 50 visible nodes and gives each one its abilities in one batch', function (): void {
    for ($i = 0; $i < 50; $i++) {
        AccessTestHelpers::folder($this->storage, $this->folder, 'child-'.$i);
    }
    $listing = app(ExactListing::class);
    $query = Node::query()->where('parent_id', $this->folder->id);
    $queries = 0;
    $counting = true;
    DB::listen(function ($event) use (&$queries, &$counting): void {
        if ($counting && $event->connectionName === 'testing') {
            $queries++;
        }
    });
    $page = $listing->page($this->actor, $query, [['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']], 50, null, 'queries');
    $snapshots = app(NodeSnapshotMapper::class)->mapMany($page->items, Actor::user($this->actor)->ref());
    $counting = false;
    expect(count($page))->toBe(50)->and($queries)->toBeLessThanOrEqual(5);

    // The abilities of the whole page come from one batch evaluation (its own query budget is
    // pinned by AbilitiesManyTest), not from a lookup per node.
    $request = Request::create('/');
    $request->setUserResolver(fn () => $this->actor);
    $handle = app(Vaulter::class)->drive($this->storage)->actingAs($this->actor);
    $resources = NodeResource::many($snapshots, $handle->nodes()->abilitiesMany($snapshots));
    expect($resources)->toHaveCount(50);
    foreach ($resources as $resource) {
        expect($resource->resolve($request)['abilities']['view'])->toBeTrue();
    }
});

it('keeps reader keys on the persisted drive scope when a loaded relation is missing or unusable', function (): void {
    $stale = $this->storage->fresh();
    $this->storage->forceFill(['tenant_id' => 17])->save();
    $this->folder->forceFill(['tenant_id' => 17])->save();
    $mapper = app(NodeSnapshotMapper::class);
    $viewer = Actor::user($this->actor)->ref();
    $expected = $mapper->map($this->folder->fresh(), $viewer)->syncKey;
    expect($expected)->toHaveLength(43);
    $foreign = AccessTestHelpers::storage();
    $foreign->forceFill(['tenant_id' => 77])->save();
    $partial = Drive::query()->select('id')->findOrFail($this->storage->id);
    $dirty = $this->storage->fresh()->forceFill(['tenant_id' => 77]);

    foreach ([$this->storage->fresh(), null, $foreign, $partial, $dirty, $stale] as $drive) {
        $node = $this->folder->fresh()->setRelation('storage', $drive);
        expect($mapper->map($node, $viewer)->syncKey)->toBe($expected);
    }
});

it('rejects forged signatures and a cursor from another parent', function (): void {
    AccessTestHelpers::folder($this->storage, $this->folder, 'a');
    AccessTestHelpers::folder($this->storage, $this->folder, 'b');
    $url = $this->base.'/nodes?parent='.$this->folder->id.'&per_page=1';
    $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');
    $this->getJson($url.'&cursor='.urlencode($cursor.'x'))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);
    $other = AccessTestHelpers::folder($this->storage, null, 'other');
    AccessTestHelpers::grant($other, RoleRegistry::READER);
    $this->getJson($this->base.'/nodes?parent='.$other->id.'&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('keeps the last inspected key when a full page ends inside an overfetch batch', function (): void {
    for ($i = 0; $i < 5; $i++) {
        AccessTestHelpers::folder($this->storage, $this->folder, 'row-'.$i);
    }
    $cursor = null;
    $names = [];
    do {
        $response = $this->getJson($this->base.'/nodes?'.http_build_query(['parent' => (string) $this->folder->id, 'per_page' => 2, 'cursor' => $cursor]))->assertOk();
        $names = array_merge($names, array_column($response->json('data'), 'name'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($names)->toBe(['row-0', 'row-1', 'row-2', 'row-3', 'row-4']);
});

it('paginates trash in canonical default position name and id order without skipping ties', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = AccessTestHelpers::folder($this->storage, $this->folder, 'trash-'.$i)->id;
    }
    Node::query()->whereIn('id', $ids)->update(['trashed_at' => '2026-10-04 00:00:00', 'trash_root_id' => DB::raw('id')]);
    $cursor = null;
    $found = [];
    do {
        $response = $this->getJson($this->base.'/trash?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
        $found = array_merge($found, array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($found)->toBe(array_map(strval(...), $ids));
});

it('scans shared candidates across storages without totals or blocked rows', function (): void {
    config()->set('vaulter-drive.listing.scan_budget', 1);
    // The hidden candidate's drive belongs to a profile whose driver blocks it
    // administratively: a candidate by its grant, refused by the exact check.
    config()->set('vaulter-drive.access.permission_drivers.blocked', ProfileDenyDriver::class);
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default'));
    $registry->register((new Profile)->key('blocked')->permissionDriver('blocked'));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    $blocked = AccessTestHelpers::storage();
    $blocked->forceFill(['profile' => 'blocked'])->save();
    $hidden = AccessTestHelpers::folder($blocked, null, 'a-hidden');
    AccessTestHelpers::grant($hidden, RoleRegistry::READER);
    $otherStorage = AccessTestHelpers::storage();
    $visible = AccessTestHelpers::folder($otherStorage, null, 'z-visible');
    AccessTestHelpers::grant($visible, RoleRegistry::READER);
    $cursor = null;
    $ids = [];
    do {
        $response = $this->getJson('api/vaulter/v1/shared?'.http_build_query(['cursor' => $cursor]))->assertOk()->assertJsonMissingPath('meta.total');
        $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($ids)->toContain((string) $visible->id)->not->toContain((string) $hidden->id);
});

it('SEC-CURSOR binds shared-list cursors to the current actor identity', function (): void {
    $a = AccessTestHelpers::folder($this->storage, $this->folder, 'z-shared-a');
    AccessTestHelpers::grant($a, RoleRegistry::READER);
    $other = AccessTestHelpers::storage();
    $b = AccessTestHelpers::folder($other, null, 'a-shared-b');
    AccessTestHelpers::grant($b, RoleRegistry::READER, subjectId: 2);
    $query = app(SharedWithActorQuery::class);
    $options = new ListOptions(perPage: 1);
    $cursor = $query->execute($this->actor, $options)->nextCursor;
    expect($cursor)->not->toBeNull();
    $actorB = AccessTestHelpers::user(2);
    expect(array_column($query->execute($actorB, $options)->items, 'id'))->toContain($b->id);
    expect(fn () => $query->execute($actorB, $options->cursor($cursor)))
        ->toThrow(InvalidCursorException::class);
});
