<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/**
 * The change journal: a start cursor, then pages of changes for the reader with the
 * node as the reader sees it (abilities included) and the cursor to continue from.
 */
uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.features.changes', true);
    $this->drive = V1::drive(userId: 1);
    $this->base = V1::url('drives/'.$this->drive->model()->getKey().'/changes');
    $this->actingAs(V1::user(1));
});

it('follows the journal from a start cursor', function (): void {
    $cursor = $this->getJson($this->base.'/start')->assertOk()->json('data.cursor');
    expect($cursor)->toBeString();

    $folder = V1::folder($this->drive->actingAs(V1::user(1)), 'new');

    $page = $this->getJson($this->base.'?cursor='.urlencode($cursor))->assertOk();
    expect($page->json('meta.has_more'))->toBeFalse()
        ->and($page->json('meta.next_cursor'))->toBeString()->not->toBe($cursor)
        ->and($page->json('data.0.node_id'))->toBe($folder->id)
        ->and($page->json('data.0.operation'))->toBe('upsert')
        ->and($page->json('data.0.sync_key'))->toBe($page->json('data.0.node.sync_key'))
        ->and($page->json('data.0.node.id'))->toBe($folder->id)
        ->and($page->json('data.0.node.abilities.view'))->toBeTrue()
        ->and($page->json('data.0.node.etag'))->toBeString()
        ->and($page->headers->get('Cache-Control'))->toContain('no-store');

    $empty = $this->getJson($this->base.'?cursor='.urlencode($page->json('meta.next_cursor')))->assertOk();
    expect($empty->json('data'))->toBe([]);
});

it('names a removed node by its reader key only, the same key the node and its upsert carry', function (): void {
    $writer = $this->drive->actingAs(V1::user(1));
    $folder = V1::folder($writer, 'gone');
    $cursor = $this->getJson($this->base.'/start')->json('data.cursor');
    $key = $this->getJson(V1::url('nodes/'.$folder->id))->assertOk()->json('data.sync_key');
    $writer->nodes()->trash($folder);

    $page = $this->getJson($this->base.'?cursor='.urlencode($cursor))->assertOk();
    $remove = $page->json('data.0');

    expect($key)->toBeString()->toHaveLength(43)
        ->and($remove)->toBe(['operation' => 'remove', 'sync_key' => $key])
        ->and($page->getContent())->not->toContain('"node_id"')
        ->and($page->getContent())->not->toContain('"reason"');
});

it('limits a page and reports has_more', function (): void {
    $cursor = $this->getJson($this->base.'/start')->json('data.cursor');
    foreach (['a', 'b', 'c'] as $name) {
        V1::folder($this->drive->actingAs(V1::user(1)), $name);
    }

    $page = $this->getJson($this->base.'?limit=2&cursor='.urlencode($cursor))->assertOk();

    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.has_more'))->toBeTrue();
});

it('refuses a missing or foreign cursor and an out-of-range limit', function (): void {
    $other = V1::drive(userId: 1);
    $foreign = $this->getJson(V1::url('drives/'.$other->model()->getKey().'/changes/start'))->json('data.cursor');

    $this->getJson($this->base)->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $this->getJson($this->base.'?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
    $this->getJson($this->base.'?cursor='.urlencode($foreign))->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
    $this->getJson($this->base.'?limit=0&cursor='.urlencode($foreign))->assertUnprocessable()->assertJsonValidationErrors('limit');
});

it('answers profile_restriction when the journal is off', function (): void {
    config()->set('vaulter-drive.features.changes', false);

    $this->getJson($this->base.'/start')->assertForbidden()->assertJsonPath('code', 'profile_restriction');
});

it('hides the journal and its tombstones from a caller with no visible nodes', function (): void {
    $cursor = $this->drive->changes()->startCursor();
    $folder = V1::folder($this->drive, 'private');
    $this->drive->nodes()->trash($folder);
    $this->actingAs(V1::user(2));

    foreach (['/start', '?cursor='.urlencode($cursor)] as $suffix) {
        $unknown = $this->getJson(V1::url('drives/no-such-drive/changes'.$suffix))->assertNotFound();
        $hidden = $this->getJson($this->base.$suffix)->assertNotFound();
        $expected = $unknown->json();
        $actual = $hidden->json();
        unset($expected['request_id'], $actual['request_id']);
        expect($actual)->toBe($expected)->and($hidden->json('code'))->toBe('drive_not_found');
    }
});

it('keeps the journal usable by a caller granted only a nested folder', function (): void {
    $folder = V1::folder($this->drive, 'shared');
    AccessTestHelpers::grant($folder, RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $cursor = $this->getJson($this->base.'/start')->assertOk()->json('data.cursor');
    $child = V1::folder($this->drive, 'child', $folder->id);

    $this->getJson($this->base.'?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('data.0.node_id', $child->id);
});

it('turns a stranger away without walking the nodes of the drive', function (): void {
    $this->actingAs(V1::user(2));
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->base.'/start')->assertNotFound()->assertJsonPath('code', 'drive_not_found');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $count();
    $small = $count();
    $owner = $this->drive->actingAs(V1::user(1));
    foreach (range(1, 210) as $index) {
        V1::folder($owner, 'f'.$index);
    }

    expect($count())->toBe($small);
});

it('varies the node tag with the reader and the key generation and keeps the read private and revalidated', function (): void {
    $folder = V1::folder($this->drive, 'tagged');
    AccessTestHelpers::grant($folder, RoleRegistry::WRITER, subjectId: 2);
    $url = V1::url('nodes/'.$folder->id);

    $owner = $this->getJson($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, no-transform, private')
        ->assertHeader('Vary');
    $this->actingAs(V1::user(2));
    $other = $this->getJson($url)->assertOk();
    $this->actingAs(V1::user(1));

    expect($owner->headers->get('Vary'))->toContain('Authorization')
        ->and($other->json('data.sync_key'))->not->toBe($owner->json('data.sync_key'))
        ->and($other->headers->get('ETag'))->not->toBe($owner->headers->get('ETag'))
        ->and($owner->headers->get('ETag'))->toBe($owner->json('data.etag'));

    // The same reader's tag still moves with the node (the If-Match version semantics are the tag's own) ...
    $renamed = $this->patchJson($url, ['name' => 'renamed'], ['If-Match' => $owner->headers->get('ETag')])->assertOk();
    expect($renamed->headers->get('ETag'))->not->toBe($owner->headers->get('ETag'));

    // ... and with the key generation: a tag read before the bump no longer matches, a fresh one does.
    $before = $this->getJson($url)->assertOk();
    config()->set('vaulter-drive.changes.key_generation', 2);
    $after = $this->getJson($url)->assertOk();
    expect($after->json('data.sync_key'))->not->toBe($before->json('data.sync_key'))
        ->and($after->headers->get('ETag'))->not->toBe($before->headers->get('ETag'));
    $this->patchJson($url, ['name' => 'stale'], ['If-Match' => $before->headers->get('ETag')])->assertStatus(412);
    $this->patchJson($url, ['name' => 'fresh'], ['If-Match' => $after->headers->get('ETag')])->assertOk()->assertJsonPath('data.name', 'fresh');
});

it('gives an anonymous-style node answer no key and a null key the same tag as itself', function (): void {
    $folder = V1::folder($this->drive, 'plain');
    $snapshot = $this->drive->actingAs(V1::user(1))->nodes()->find($folder->id);
    $a = new NodeResource($snapshot->withSyncKey(null), NodeAbilities::denied());
    $b = new NodeResource($snapshot->withSyncKey(null), NodeAbilities::denied());
    $keyed = new NodeResource($snapshot->withSyncKey(str_repeat('a', 43)), NodeAbilities::denied());

    expect($a->toArray(request()))->toHaveKey('sync_key', null)
        ->and($a->etag())->toBe($b->etag())
        ->and($keyed->etag())->not->toBe($a->etag());
});
