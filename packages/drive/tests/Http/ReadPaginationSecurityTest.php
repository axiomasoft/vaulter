<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Crypt;
use Vaulter\Drive\Access\ExactListing;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

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

/** Exactly 255 UTF-8 bytes: 40 emoji (160) + 20 quotes (20) + 5 U+2028 (15) + 30 Cyrillic letters (60). */
function p52Name255(): string
{
    return str_repeat("\u{1F600}", 40).str_repeat('"', 20).str_repeat("\u{2028}", 5).str_repeat("\u{044F}", 30);
}

it('keeps a node cursor encrypted, bounded at 2048 bytes and free of the last name for an exactly 255-byte unicode name', function (): void {
    $name = p52Name255();
    expect(strlen($name))->toBe(255);
    AccessTestHelpers::folder($this->storage, $this->folder, $name);
    AccessTestHelpers::folder($this->storage, $this->folder, $name.'z');
    $url = $this->base.'/nodes?parent='.$this->folder->id.'&per_page=1';

    $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');

    expect($cursor)->toBeString()->and(strlen($cursor))->toBeLessThanOrEqual(2048)
        ->and($cursor)->not->toContain($name)->and($cursor)->not->toContain('parent');
    $envelope = json_decode(Crypt::decrypt($cursor, false), true);
    expect($envelope)->toHaveKeys(['v', 'p', 'b', 'k'])->and($envelope['b'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($envelope))->not->toContain('parent')->and($envelope['k'][0])->toBe($name);
    $second = $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk();
    expect($second->json('data'))->toHaveCount(1)->and($second->json('data.0.name'))->toBe($name.'z');
});

it('keeps a long typed host and filter scope out of the cursor, seeks raw keys, keeps empty-scan progress and rejects a re-bound scope', function (): void {
    $name = p52Name255();
    $first = AccessTestHelpers::folder($this->storage, $this->folder, $name);
    AccessTestHelpers::folder($this->storage, $this->folder, $name.'z');
    $listing = app(ExactListing::class);
    $order = [['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']];
    $scope = json_encode(['host' => str_repeat('Host\\Модель\\', 120), 'filter' => ['q' => str_repeat("\u{2028}\"/", 400), 'kinds' => ['folder']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(strlen($scope))->toBeGreaterThan(4096);
    $query = fn () => Node::query()->where('parent_id', $this->folder->id);

    $page = $listing->page($this->actor, $query(), $order, 1, null, $scope);

    expect($page->nextCursor)->toBeString()->and(strlen($page->nextCursor))->toBeLessThanOrEqual(2048)
        ->and($page->nextCursor)->not->toContain('Host')->and(json_encode(json_decode(Crypt::decrypt($page->nextCursor, false), true)))->not->toContain('Host');
    $envelope = json_decode(Crypt::decrypt($page->nextCursor, false), true);
    expect($envelope['k'])->toBe([$name, $first->getKey()])
        ->and($listing->page($this->actor, $query(), $order, 1, $page->nextCursor, $scope)->items)->toHaveCount(1);
    foreach ([$scope.' ', 'x', substr($scope, 1)] as $other) {
        expect(fn () => $listing->page($this->actor, $query(), $order, 1, $page->nextCursor, $other))->toThrow(InvalidCursorException::class);
    }

    // Empty-scan progress: nothing visible in the budget still yields a sealed continuation, not a hidden count.
    AccessTestHelpers::folder($this->storage, null, 'hidden-1');
    AccessTestHelpers::folder($this->storage, null, 'hidden-2');
    config()->set('vaulter-drive.listing.scan_budget', 1);
    $roots = fn () => Node::query()->where('name', 'like', 'hidden-%');
    $empty = $listing->page($this->actor, $roots(), $order, 5, null, 'roots');
    expect($empty->items)->toBe([])->and($empty->scanned)->toBe(1)->and($empty->nextCursor)->toBeString();
    config()->set('vaulter-drive.listing.scan_budget', 1000);
    expect($listing->page($this->actor, $roots(), $order, 5, $empty->nextCursor, 'roots')->items)->toBe([])
        ->and(fn () => $listing->page($this->actor, $roots(), $order, 5, $empty->nextCursor, 'roots-other'))->toThrow(InvalidCursorException::class);
});

it('rejects a legacy signed cursor, an oversized cursor and a cursor re-bound to another scope', function (): void {
    AccessTestHelpers::folder($this->storage, $this->folder, 'a');
    AccessTestHelpers::folder($this->storage, $this->folder, 'b');
    $url = $this->base.'/nodes?parent='.$this->folder->id.'&per_page=1';
    $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');
    $forged = Crypt::encrypt(json_encode(['v' => 1, 'p' => 'node-list', 'b' => str_repeat('0', 64), 'k' => ['a', 1]]), false);
    $legacy = rtrim(strtr(base64_encode('{"c":"x","k":["a",1],"o":[]}'), '+/', '-_'), '=').'.sig';

    foreach ([$legacy, str_repeat('A', 2049), $forged, $cursor.'x'] as $bad) {
        $this->getJson($url.'&cursor='.urlencode($bad))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);
    }
    $other = AccessTestHelpers::folder($this->storage, null, 'other');
    AccessTestHelpers::grant($other, RoleRegistry::READER);
    $this->getJson($this->base.'/nodes?parent='.$other->id.'&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('binds a node cursor to the typed principal: another user and the same id under another morph type get 422', function (): void {
    AccessTestHelpers::folder($this->storage, $this->folder, 'a');
    AccessTestHelpers::folder($this->storage, $this->folder, 'b');
    $url = $this->base.'/nodes?parent='.$this->folder->id.'&per_page=1';
    $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');
    $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk();

    $other = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($this->folder, RoleRegistry::READER, subjectId: 2);
    $this->actingAs($other);
    $this->getJson($url.'&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);

    $morph = new class extends User
    {
        public function getMorphClass(): string
        {
            return 'other-principal';
        }
    };
    $morph->forceFill(['id' => 1]);
    $morph->exists = true;
    AccessTestHelpers::grant($this->folder, RoleRegistry::READER, subjectId: 1, subjectType: 'other-principal');
    $this->actingAs($morph);
    $this->getJson($url.'&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
