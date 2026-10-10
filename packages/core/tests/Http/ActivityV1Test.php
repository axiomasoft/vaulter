<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/** The drive and node activity feeds: real events, the page envelope, the reader's view. */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.activity', true);
    $this->drive = V1::drive(userId: 1);
    $this->actingAs(V1::user(1));
});

it('pages the drive feed with {data, meta: {next_cursor, per_page}} and string ids', function (): void {
    foreach (['a', 'b', 'c'] as $name) {
        V1::folder($this->drive->actingAs(V1::user(1)), $name);
    }

    $first = $this->getJson(V1::url('drives/'.$this->drive->model()->getKey().'/activity?per_page=2'))->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.per_page'))->toBe(2)
        ->and($first->json('meta.next_cursor'))->toBeString()
        ->and($first->json())->not->toHaveKey('links')
        ->and($first->json('data.0.id'))->toBeString()
        ->and($first->json('data.0.actor'))->toBe(['type' => V1::user()->getMorphClass(), 'id' => '1']);

    $next = $this->getJson(V1::url('drives/'.$this->drive->model()->getKey().'/activity?per_page=2&cursor='.urlencode($first->json('meta.next_cursor'))))->assertOk();
    expect(array_intersect(array_column($next->json('data'), 'id'), array_column($first->json('data'), 'id')))->toBe([]);
});

it('feeds one node with node_id set and hides it from a stranger', function (): void {
    $folder = V1::folder($this->drive->actingAs(V1::user(1)), 'watched');

    $entries = $this->getJson(V1::url('nodes/'.$folder->id.'/activity'))->assertOk()->json('data');
    expect(array_unique(array_column($entries, 'node_id')))->toBe([$folder->id]);

    $this->actingAs(V1::user(3))->getJson(V1::url('nodes/'.$folder->id.'/activity'))->assertNotFound();
});

it('opens a node feed to a reader of that node only', function (): void {
    $folder = V1::folder($this->drive, 'reader');
    AccessTestHelpers::grant(DriveTestHelpers::model($folder), RoleRegistry::READER, subjectId: 4);

    $this->actingAs(V1::user(4))->getJson(V1::url('nodes/'.$folder->id.'/activity'))->assertOk();
    $this->actingAs(V1::user(4))->getJson(V1::url('drives/'.$this->drive->model()->getKey().'/activity'))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
});
