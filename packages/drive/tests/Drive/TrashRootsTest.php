<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
});

it('trashing a folder stamps the root on every member and the root points at itself', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);
    $live = $drive->folders()->create('Live');

    $trashed = $drive->nodes()->trash($folder);

    expect($trashed->trashRoot)->toBeTrue()
        ->and((string) DriveTestHelpers::model($folder)->trash_root_id)->toBe((string) $folder->id)
        ->and((string) DriveTestHelpers::model($sub)->trash_root_id)->toBe((string) $folder->id)
        ->and(DriveTestHelpers::model($live)->trash_root_id)->toBeNull();
});

it('lists only the explicit roots and flags them as trash roots', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $member = $drive->folders()->create('Member', $folder);
    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);

    $items = $drive->trash()->list()->items;
    $ids = array_map(static fn ($node) => $node->id, $items);

    expect($ids)->toContain($folder->id)->toContain($early->id)->not->toContain($member->id)
        ->and(array_unique(array_map(static fn ($node) => $node->trashRoot, $items)))->toBe([true]);
    // A member mapped to a snapshot is not a root.
    $snapshot = app(NodeSnapshotMapper::class)->map(DriveTestHelpers::model($member), $drive->actor()->ref());
    expect($snapshot->trashRoot)->toBeFalse();
});

it('restoring a root keeps an independently trashed descendant in the trash with its own root', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $member = $drive->folders()->create('Member', $folder);
    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);

    $drive->trash()->restore($folder);

    expect(DriveTestHelpers::model($member)->trashed_at)->toBeNull()
        ->and(DriveTestHelpers::model($member)->trash_root_id)->toBeNull()
        ->and(DriveTestHelpers::model($early)->trashed_at)->not->toBeNull()
        ->and((string) DriveTestHelpers::model($early)->trash_root_id)->toBe((string) $early->id);

    // The remaining root is restorable to its original (now live) parent.
    expect($drive->trash()->restore($early)->parentId)->toBe($folder->id);
});

it('purging a root removes the whole subtree including descendants trashed earlier', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);

    $drive->trash()->purge($folder);

    expect(Node::query()->whereKey([$folder->id, $early->id])->count())->toBe(0);
});

it('retention counts from the root and the command purges only roots past it', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);
    // The root was trashed long ago, the descendant is newer: the root's clock decides.
    DriveTestHelpers::model($folder)->forceFill(['trashed_at' => Carbon::now()->subDays(60)])->save();
    $recent = $drive->folders()->create('Recent');
    $drive->nodes()->trash($recent);

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    expect(Node::query()->whereKey([$folder->id, $early->id])->count())->toBe(0)
        ->and(Node::query()->whereKey($recent->id)->count())->toBe(1);
});

it('empty purges every root and nothing else', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');
    $child = $drive->folders()->create('Child', $a);
    $b = $drive->folders()->create('B');
    $live = $drive->folders()->create('Live');
    $drive->nodes()->trash($child);
    $drive->nodes()->trash($a);
    $drive->nodes()->trash($b);

    expect($drive->trash()->empty())->toBe(2)
        ->and(Node::query()->whereKey([$a->id, $child->id, $b->id])->count())->toBe(0)
        ->and(Node::query()->whereKey($live->id)->count())->toBe(1);
});

it('the retention candidates are the roots only, and the row lock refuses a member', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $member = $drive->folders()->create('Member', $folder);
    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);

    $candidates = app(NodeReadRepository::class)->trashedBefore(IlluminateCarbon::now()->addMinute())->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    $expected = collect([$folder->id, $early->id])->map(fn ($id) => (string) $id)->sort()->values()->all();
    expect($candidates)->toBe($expected);

    $store = app(NodeStoreRepository::class);
    app(VaulterDatabase::class)->transaction(function () use ($store, $member, $folder): void {
        expect($store->lockTrashedRoot($member->id, IlluminateCarbon::now()->addMinute()))->toBeNull()
            ->and($store->lockTrashedRoot($folder->id, IlluminateCarbon::now()->addMinute())?->getKey())->toEqual($folder->id);
    });
});

it('answers the REST restore of a member and of a node without a home with the stable codes', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $member = $drive->folders()->create('Member', $folder);
    $drive->nodes()->trash($folder);

    $actor = $drive->actingAs((new TestUser)->forceFill(['id' => 1]));
    $this->postJson('api/vaulter/v1/nodes/'.$member->id.'/restore', [], ['If-Match' => DriveV1::tagFor($actor, $member->id)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_trashed_by_ancestor')
        ->assertJsonPath('meta.context.trash_root_id', (string) $folder->id);

    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);
    $drive->nodes()->trash($child);
    $drive->nodes()->trash($parent);
    $this->postJson('api/vaulter/v1/nodes/'.$child->id.'/restore', [], ['If-Match' => DriveV1::tagFor($actor, $child->id)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'restore_destination_required');
});
