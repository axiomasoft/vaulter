<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\Http\V1;

/**
 * The change journal: a start cursor, then pages of changes for the reader with the
 * node as the reader sees it (abilities included) and the cursor to continue from.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.changes', true);
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
        ->and($page->json('data.0.node.id'))->toBe($folder->id)
        ->and($page->json('data.0.node.abilities.view'))->toBeTrue()
        ->and($page->json('data.0.node.etag'))->toBeString()
        ->and($page->headers->get('Cache-Control'))->toContain('no-store');

    $empty = $this->getJson($this->base.'?cursor='.urlencode($page->json('meta.next_cursor')))->assertOk();
    expect($empty->json('data'))->toBe([]);
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
    config()->set('vaulter.features.changes', false);

    $this->getJson($this->base.'/start')->assertForbidden()->assertJsonPath('code', 'profile_restriction');
});
