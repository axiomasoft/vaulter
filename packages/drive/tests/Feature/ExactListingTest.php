<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Vaulter\Access\ExactListing;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actor = AccessTestHelpers::user();
    $this->actingAs($this->actor);
    $this->storage = AccessTestHelpers::storage();
    $this->folder = AccessTestHelpers::folder($this->storage, null, 'parent');
    AccessTestHelpers::grant($this->folder, GrantLevel::Reader, scope: GrantScope::Subtree);
    $this->base = 'api/vaulter/storages/'.$this->storage->id;
});

it('V09 reaches the 301st candidate across empty pages and hides denied nodes everywhere', function (): void {
    config()->set('vaulter.listing.scan_budget', 100);
    $hidden = [];
    for ($i = 0; $i < 300; $i++) {
        $child = AccessTestHelpers::folder($this->storage, $this->folder, sprintf('candidate-%04d', $i));
        AccessTestHelpers::grant($child, GrantLevel::Reader, effect: Effect::Deny);
        $hidden[] = $child->id;
    }
    $visible = AccessTestHelpers::folder($this->storage, $this->folder, 'candidate-0300');
    $cursor = null;
    $found = [];
    $emptyPages = 0;
    do {
        $url = $this->base.'/nodes?'.http_build_query(['parent_id' => $this->folder->id, 'per_page' => 50, 'cursor' => $cursor]);
        $response = $this->getJson($url)->assertOk()->assertJsonMissingPath('meta.total');
        $data = $response->json('data');
        $cursor = $response->json('meta.next_cursor');
        if ($data === []) {
            $emptyPages++;
        }
        $found = array_merge($found, array_column($data, 'id'));
        expect($emptyPages)->toBeLessThan(10);
    } while ($cursor !== null);
    expect($emptyPages)->toBe(3)->and($found)->toBe([$visible->id]);
    $this->getJson('api/vaulter/nodes/'.$this->folder->id)->assertOk()->assertJsonCount(1, 'data.children')->assertJsonPath('data.children.0.id', $visible->id);
    config()->set('vaulter.listing.scan_budget', 1000);
    $this->getJson($this->base.'/search?q=candidate')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id)->assertJsonMissingPath('meta.total');
    Node::query()->whereIn('id', [...$hidden, $visible->id])->update(['trashed_at' => now()]);
    $this->getJson($this->base.'/trash')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id)->assertJsonMissingPath('meta.total');
});

it('V22 enforces a child deny after the Gate role lane allows the workspace', function (): void {
    config()->set('vaulter.access.permission_driver', 'gate');
    Gate::define('vaulter.view', fn (): bool => true);
    $hidden = AccessTestHelpers::folder($this->storage, $this->folder, 'hidden');
    $visible = AccessTestHelpers::folder($this->storage, $this->folder, 'visible');
    AccessTestHelpers::grant($hidden, GrantLevel::Reader, effect: Effect::Deny);
    $this->getJson($this->base.'/nodes?parent_id='.$this->folder->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
});

it('uses at most five Vaulter queries for 50 visible nodes including resource abilities', function (): void {
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
    $request = Request::create('/');
    $request->setUserResolver(fn () => $this->actor);
    foreach ($page->items as $node) {
        $data = NodeResource::make($node)->withGrantSet($listing->grantSetFor($node))->resolve($request);
        expect($data['abilities']['view'])->toBeTrue();
    }
    $counting = false;
    expect(count($page))->toBe(50)->and($queries)->toBeLessThanOrEqual(5);
});

it('rejects forged signatures and a cursor from another parent', function (): void {
    AccessTestHelpers::folder($this->storage, $this->folder, 'a');
    AccessTestHelpers::folder($this->storage, $this->folder, 'b');
    $url = $this->base.'/nodes?parent_id='.$this->folder->id.'&per_page=1';
    $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');
    $this->getJson($url.'&cursor='.urlencode($cursor.'x'))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);
    $other = AccessTestHelpers::folder($this->storage, null, 'other');
    AccessTestHelpers::grant($other, GrantLevel::Reader);
    $this->getJson($this->base.'/nodes?parent_id='.$other->id.'&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('keeps the last inspected key when a full page ends inside an overfetch batch', function (): void {
    for ($i = 0; $i < 5; $i++) {
        AccessTestHelpers::folder($this->storage, $this->folder, 'row-'.$i);
    }
    $cursor = null;
    $names = [];
    do {
        $response = $this->getJson($this->base.'/nodes?'.http_build_query(['parent_id' => $this->folder->id, 'per_page' => 2, 'cursor' => $cursor]))->assertOk();
        $names = array_merge($names, array_column($response->json('data'), 'name'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($names)->toBe(['row-0', 'row-1', 'row-2', 'row-3', 'row-4']);
});

it('does not skip equal sort values during descending trash pagination', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = AccessTestHelpers::folder($this->storage, $this->folder, 'trash-'.$i)->id;
    }
    Node::query()->whereIn('id', $ids)->update(['trashed_at' => '2026-10-04 00:00:00']);
    $cursor = null;
    $found = [];
    do {
        $response = $this->getJson($this->base.'/trash?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
        $found = array_merge($found, array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($found)->toBe(array_reverse($ids));
});

it('scans shared candidates across storages without totals or denied rows', function (): void {
    config()->set('vaulter.listing.scan_budget', 1);
    $hidden = AccessTestHelpers::folder($this->storage, $this->folder, 'a-hidden');
    AccessTestHelpers::grant($hidden, GrantLevel::Reader);
    AccessTestHelpers::grant($hidden, GrantLevel::Reader, effect: Effect::Deny);
    $otherStorage = AccessTestHelpers::storage();
    $visible = AccessTestHelpers::folder($otherStorage, null, 'z-visible');
    AccessTestHelpers::grant($visible, GrantLevel::Reader);
    $cursor = null;
    $ids = [];
    do {
        $response = $this->getJson('api/vaulter/shared?'.http_build_query(['cursor' => $cursor]))->assertOk()->assertJsonMissingPath('meta.total');
        $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});
