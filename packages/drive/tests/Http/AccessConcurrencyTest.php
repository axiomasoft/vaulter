<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Two clients holding the same access tag of a node (run one after the other on SQLite; the forked race on a
 * server engine is Concurrency/ShareVersionRaceTest): whichever writes first lands, the second gets 412 stale_write
 * and changes nothing. The access tag is its own token: grants and inheritance never move the node's sequence,
 * nor does a share link.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.features.grants', true);
    config()->set('vaulter-drive.features.share_links', true);
    $this->drive = V1::drive(userId: 1);
    $this->folder = V1::folder($this->drive, 'team');
    $this->actingAs(V1::user(1));
    $this->grants = V1::url('nodes/'.$this->folder->id.'/grants');
    $this->tag = fn (): string => (string) $this->getJson($this->grants)->assertOk()->json('etag');
    $this->grant = fn (int $subject): string => (string) $this->postJson($this->grants, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $subject], 'role' => RoleRegistry::READER])->assertCreated()->json('data.id');
});

it('lets a revoke win over a restriction read under the same tag', function (): void {
    $grant = ($this->grant)(7);
    $tag = ['If-Match' => ($this->tag)()];

    $this->deleteJson($this->grants.'/'.$grant, [], $tag)->assertNoContent();
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], $tag)->assertStatus(412)->assertJsonPath('code', 'stale_write');

    expect(DriveTestHelpers::model($this->folder)->inherit_grants)->toBeTrue();
});

it('lets a restriction win over a revoke read under the same tag', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);
    $grant = ($this->grant)(7);
    $tag = ['If-Match' => ($this->tag)()];

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], $tag)->assertOk();
    $this->deleteJson($this->grants.'/'.$grant, [], $tag)->assertStatus(412)->assertJsonPath('code', 'stale_write');

    expect(NodeGrant::query()->whereKey($grant)->exists())->toBeTrue();
});

it('refuses the second of two revokes of different grants read under the same tag', function (): void {
    $first = ($this->grant)(7);
    $second = ($this->grant)(8);
    $tag = ['If-Match' => ($this->tag)()];

    $this->deleteJson($this->grants.'/'.$first, [], $tag)->assertNoContent();
    $this->deleteJson($this->grants.'/'.$second, [], $tag)->assertStatus(412);

    expect(NodeGrant::query()->whereKey($second)->exists())->toBeTrue()
        ->and(DriveTestHelpers::model($this->folder)->access_version)->toBe(3);
});

it('makes a tag stale by a grant created meanwhile, which itself needs no tag', function (): void {
    $grant = ($this->grant)(7);
    $tag = ['If-Match' => ($this->tag)()];

    ($this->grant)(8);

    $this->deleteJson($this->grants.'/'.$grant, [], $tag)->assertStatus(412);
});

it('keeps the node sequence through grants, inheritance and share links (CC2)', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);
    $sequence = DriveTestHelpers::model($this->folder)->sequence;
    $shares = V1::url('nodes/'.$this->folder->id.'/shares');

    $grant = ($this->grant)(7);
    $this->deleteJson($this->grants.'/'.$grant, [], ['If-Match' => ($this->tag)()])->assertNoContent();
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], ['If-Match' => ($this->tag)()])->assertOk();
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restore'), [], ['If-Match' => ($this->tag)()])->assertOk();
    $share = $this->postJson($shares, ['role' => RoleRegistry::READER])->assertCreated();
    $updated = $this->patchJson($shares.'/'.$share->json('data.id'), ['max_uses' => 3], ['If-Match' => $share->json('data.etag')])->assertOk();
    $rotated = $this->postJson($shares.'/'.$share->json('data.id').'/rotate', [], ['If-Match' => $updated->json('data.etag')])->assertOk();
    $this->deleteJson($shares.'/'.$share->json('data.id'), [], ['If-Match' => $rotated->json('data.etag')])->assertNoContent();

    expect(DriveTestHelpers::model($this->folder)->sequence)->toBe($sequence)
        ->and(DriveTestHelpers::model($this->folder)->access_version)->toBe(4);
});
