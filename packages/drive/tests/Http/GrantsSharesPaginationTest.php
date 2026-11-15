<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Grants and shares lists are bounded, cursor-paginated pages (default 50, max 200) in the PageResponse envelope;
 * the grants page keeps its top-level access `etag`. Cursors are sealed (encrypted, scope-bound) and the public share
 * children cursor holds the raw Laravel parameters only inside the encrypted payload.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.features.share_links', true);
    $this->drive = V1::drive(userId: 1);
    $this->node = V1::folder($this->drive, 'shared');
    $this->other = V1::folder($this->drive, 'other');
    $this->actingAs(V1::user(1));
    $this->grants = V1::url('nodes/'.$this->node->id.'/grants');
    $this->shares = V1::url('nodes/'.$this->node->id.'/shares');
    $this->otherGrants = V1::url('nodes/'.$this->other->id.'/grants');
});

it('pages grants two pages then an empty cursor, keeping the access etag on every page', function (): void {
    foreach ([7, 8, 9] as $user) {
        $this->postJson($this->grants, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $user], 'role' => RoleRegistry::READER])->assertCreated();
    }
    $first = $this->getJson($this->grants.'?per_page=2')->assertOk();
    $cursor = $first->json('meta.next_cursor');
    expect($first->json('data'))->toHaveCount(2)->and($first->json('meta.per_page'))->toBe(2)
        ->and($cursor)->toBeString()->and(strlen($cursor))->toBeLessThanOrEqual(2048)
        ->and($first->json('etag'))->toMatch('/^"access\.'.preg_quote((string) $this->node->id, '/').'\.\d+"$/');
    $second = $this->getJson($this->grants.'?per_page=2&cursor='.urlencode($cursor))->assertOk();
    $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
    expect($second->json('data'))->toHaveCount(1)->and($second->json('meta.next_cursor'))->toBeNull()
        ->and($second->json('etag'))->toBe($first->json('etag'))->and(array_unique($ids))->toHaveCount(3);
    $full = $this->getJson($this->grants)->assertOk();
    expect($full->json('meta.per_page'))->toBe(50)->and(array_column($full->json('data'), 'id'))->toBe($ids);
});

it('pages shares and never lists a token', function (): void {
    foreach ([1, 2, 3] as $max) {
        $this->postJson($this->shares, ['role' => RoleRegistry::READER, 'audience' => 'authenticated', 'max_uses' => $max])->assertCreated();
    }
    $first = $this->getJson($this->shares.'?per_page=2')->assertOk();
    $second = $this->getJson($this->shares.'?per_page=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk();
    expect($first->json('data'))->toHaveCount(2)->and($second->json('data'))->toHaveCount(1)->and($second->json('meta.next_cursor'))->toBeNull()
        ->and(array_unique(array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))))->toHaveCount(3)
        ->and(json_encode([$first->json(), $second->json()]))->not->toContain('"token"')->and($first->json('meta.per_page'))->toBe(2);
    $this->getJson($this->shares.'?per_page=200')->assertOk()->assertJsonPath('meta.per_page', 200);
});

it('rejects an out-of-range per_page and a malformed, foreign or cross-list grants/shares cursor', function (): void {
    foreach ([7, 8] as $user) {
        $this->postJson($this->grants, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $user], 'role' => RoleRegistry::READER])->assertCreated();
        $this->postJson($this->otherGrants, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $user], 'role' => RoleRegistry::READER])->assertCreated();
    }
    $this->postJson($this->shares, ['role' => RoleRegistry::READER, 'audience' => 'authenticated'])->assertCreated();
    $this->postJson($this->shares, ['role' => RoleRegistry::READER, 'audience' => 'authenticated'])->assertCreated();
    $grantCursor = $this->getJson($this->grants.'?per_page=1')->json('meta.next_cursor');
    $shareCursor = $this->getJson($this->shares.'?per_page=1')->json('meta.next_cursor');
    $raw = rtrim(strtr(base64_encode(json_encode(['id' => 1, 'created_at' => '2026-01-01', '_pointsToNextItems' => true])), '+/', '-_'), '=');

    foreach ([0, 201, 'x'] as $bad) {
        $this->getJson($this->grants.'?per_page='.$bad)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson($this->shares.'?per_page='.$bad)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
    foreach ([[$this->grants, $shareCursor], [$this->shares, $grantCursor], [$this->otherGrants, $grantCursor], [$this->grants, $raw], [$this->shares, $raw], [$this->grants, $grantCursor.'x'], [$this->grants, str_repeat('A', 2049)]] as [$url, $cursor]) {
        $this->getJson($url.'?per_page=1&cursor='.urlencode($cursor))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
});

it('seals the grants cursor: encrypted, scope-bound, raw parameters only inside the payload', function (): void {
    foreach ([7, 8] as $user) {
        $this->postJson($this->grants, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $user], 'role' => RoleRegistry::READER])->assertCreated();
    }
    $cursor = $this->getJson($this->grants.'?per_page=1')->json('meta.next_cursor');
    $envelope = json_decode(Crypt::decrypt($cursor, false), true);

    expect(json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true)['mac'] ?? null)->toBeString()->and($envelope)->toHaveKeys(['v', 'p', 'b', 'k'])
        ->and($envelope['p'])->toBe('grants-list')->and($envelope['b'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(array_keys($envelope['k']['parameters']))->toBe(['created_at', 'id'])->and($envelope['k']['next'])->toBeTrue();
});

it('seals the public share children cursor with raw Laravel parameters, bound to its own link', function (): void {
    $folder = V1::folder($this->drive, 'album');
    foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
        V1::file($this->drive, $name, $name, parent: $folder->id);
    }
    $row = DriveTestHelpers::model($folder);
    ShareTestHelpers::share($row, token: 'page-one', scope: GrantScope::Subtree);
    ShareTestHelpers::share($row, token: 'page-two', scope: GrantScope::Subtree);

    $first = $this->getJson(V1::url('shares/page-one/nodes?per_page=2'))->assertOk();
    $cursor = $first->json('meta.next_cursor');
    $envelope = json_decode(Crypt::decrypt($cursor, false), true);
    expect($cursor)->toBeString()->and(strlen($cursor))->toBeLessThanOrEqual(2048)
        ->and($envelope['p'])->toBe('share-children')->and(array_keys($envelope['k']['parameters']))->toBe(['name', 'id'])
        ->and($envelope['k']['parameters']['name'])->toBe('b.txt')->and($envelope['k']['next'])->toBeTrue()
        ->and(json_encode($first->json()))->not->toContain('_pointsToNextItems')->and(json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true))->not->toHaveKey('_pointsToNextItems');
    $rest = $this->getJson(V1::url('shares/page-one/nodes?per_page=2&cursor='.urlencode($cursor)))->assertOk();
    expect(array_column($rest->json('data'), 'name'))->toBe(['c.txt'])->and($rest->json('meta.next_cursor'))->toBeNull();

    $legacy = rtrim(strtr(base64_encode(json_encode(['name' => 'b.txt', 'id' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '=');
    foreach ([$legacy, $cursor.'x', 'garbage'] as $bad) {
        $this->getJson(V1::url('shares/page-one/nodes?per_page=2&cursor='.urlencode($bad)))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
    // A cursor minted for another link of the same folder is a different scope.
    $this->getJson(V1::url('shares/page-two/nodes?per_page=2&cursor='.urlencode($cursor)))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(SharedNodeResource::opaqueId($folder->id))->toBeString();
});
