<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/**
 * Trash, restore and purge over v1. Restore and purge act on a trashed node, so their
 * If-Match is the tag the trash listing showed; a stale one changes nothing — a purge
 * in particular removes no row and no stored byte.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

it('trashes with If-Match, lists the trash root and restores it with the listed tag', function (): void {
    $folder = V1::folder($this->drive, 'project');

    $this->postJson(V1::url('nodes/'.$folder->id.'/trash'), [], ['If-Match' => DriveV1::etag($this, $folder->id)])
        ->assertOk()
        ->assertJsonPath('data.trash_root', true);
    $this->getJson(V1::url('nodes/'.$folder->id))->assertNotFound();

    $listed = $this->getJson(V1::url('drives/'.$this->driveId.'/trash'))->assertOk()->assertJsonPath('data.0.id', $folder->id);
    expect($listed->json('meta'))->toBe(['next_cursor' => null, 'per_page' => 50]);

    $restored = $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), [], ['If-Match' => $listed->json('data.0.etag')])
        ->assertOk()
        ->assertJsonPath('data.trashed_at', null);
    expect($restored->headers->get('ETag'))->toBe($restored->json('data.etag'));
});

it('refuses a restore with a stale tag and keeps the node in the trash', function (): void {
    $folder = V1::folder($this->drive, 'stale');
    $this->drive->nodes()->trash($folder->id);
    $etag = DriveV1::trashedEtag($this, $this->driveId, $folder->id);
    // Any change of the trashed node's sequence makes the listed tag stale.
    app(VaulterDatabase::class)->write(static fn () => Node::query()->whereKey($folder->id)->increment('sequence'));

    $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), [], ['If-Match' => $etag])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch');

    expect(DriveTestHelpers::model($folder)->trashed_at)->not->toBeNull();
});

it('restores next to a same-named sibling under a free name by default', function (): void {
    $folder = V1::folder($this->drive, 'twin');
    $this->drive->nodes()->trash($folder->id);
    V1::folder($this->drive, 'twin');

    $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), [], ['If-Match' => DriveV1::trashedEtag($this, $this->driveId, $folder->id)])
        ->assertOk()
        ->assertJsonPath('data.name', 'twin (2)');
});

it('refuses on_conflict=fail when the name is taken', function (): void {
    $folder = V1::folder($this->drive, 'clash');
    $this->drive->nodes()->trash($folder->id);
    V1::folder($this->drive, 'clash');

    $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), ['on_conflict' => 'fail'], ['If-Match' => DriveV1::trashedEtag($this, $this->driveId, $folder->id)])
        ->assertStatus(409);
});

it('purges only from the trash: a live node is 409 node_not_trashed', function (): void {
    $file = V1::file($this->drive, 'live.txt');

    $this->deleteJson(V1::url('nodes/'.$file->id), [], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_not_trashed');

    expect(Node::query()->whereKey($file->id)->exists())->toBeTrue();
});

it('refuses a purge with a stale tag and deletes nothing', function (): void {
    $file = V1::file($this->drive, 'kept.txt', 'kept bytes');
    $this->drive->nodes()->trash($file->id);
    $etag = DriveV1::trashedEtag($this, $this->driveId, $file->id);
    app(VaulterDatabase::class)->write(static fn () => Node::query()->whereKey($file->id)->increment('sequence'));
    $used = $this->drive->quota()->usedBytes;

    $this->deleteJson(V1::url('nodes/'.$file->id), [], ['If-Match' => $etag])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch');

    expect(Node::query()->whereKey($file->id)->exists())->toBeTrue()
        ->and($this->drive->quota()->usedBytes)->toBe($used);
});

it('purges a trashed node with its listed tag and answers 204', function (): void {
    $file = V1::file($this->drive, 'gone.txt');
    $this->drive->nodes()->trash($file->id);

    $this->deleteJson(V1::url('nodes/'.$file->id), [], ['If-Match' => DriveV1::trashedEtag($this, $this->driveId, $file->id)])
        ->assertNoContent();

    expect(Node::query()->whereKey($file->id)->exists())->toBeFalse();
});

it('empties the trash and reports how many roots were purged', function (): void {
    foreach (['a', 'b'] as $name) {
        $this->drive->nodes()->trash(V1::folder($this->drive, $name)->id);
    }

    $this->deleteJson(V1::url('drives/'.$this->driveId.'/trash'))
        ->assertOk()
        ->assertExactJson(['data' => ['purged_count' => 2]]);
    $this->getJson(V1::url('drives/'.$this->driveId.'/trash'))->assertOk()->assertJsonCount(0, 'data');
});
