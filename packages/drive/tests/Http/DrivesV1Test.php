<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/**
 * The drive resource and its lifecycle over v1: show, archive, unarchive, delete. The drive's tag (`etag`, and the ETag
 * of a lifecycle answer: id and version) is the If-Match of every lifecycle change (428 without it, 412 stale_write once it changed), and
 * each change takes an Idempotency-Key.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->tag = fn (): array => ['If-Match' => (string) $this->getJson(V1::url('drives/'.$this->driveId))->assertOk()->json('data.etag')];
});

it('shows a drive with its owner, root, status and quota, and nothing internal', function (): void {
    $this->actingAs(V1::user(1));

    $response = $this->getJson(V1::url('drives/'.$this->driveId))
        ->assertOk()
        ->assertJsonPath('data.id', $this->driveId)
        ->assertJsonPath('data.root_node_id', $this->drive->root()->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.version', 0)
        ->assertJsonPath('data.etag', '"drive.'.$this->driveId.'.0"')
        ->assertJsonPath('data.used_bytes', 0);
    expect(array_keys($response->json('data')))->toBe(['id', 'owner', 'profile', 'name', 'root_node_id', 'status', 'version', 'etag', 'quota_bytes', 'used_bytes', 'created_at'])
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and($response->json('data.owner.id'))->toBeString()
        ->and($response->json('data.created_at'))->toEndWith('Z');
});

it('answers a drive whose root the caller cannot see like an unknown one', function (): void {
    $this->actingAs(V1::user(2));

    $this->getJson(V1::url('drives/'.$this->driveId))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
    $this->getJson(V1::url('drives/no-such-drive'))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
});

it('archives and unarchives with Manage, and refuses a reader', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->drive->root()), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], ($this->tag)())->assertForbidden();

    $this->actingAs(V1::user(1));
    $archived = $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], ($this->tag)())->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.version', 1);
    expect($archived->headers->get('ETag'))->toBe($archived->json('data.etag'));
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'read-only'])->assertStatus(409);
    $this->postJson(V1::url('drives/'.$this->driveId.'/unarchive'), [], ['If-Match' => $archived->json('data.etag')])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.version', 2);
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'writable'])->assertCreated();
});

it('starts deleting a drive, after which it is gone for everyone', function (): void {
    $this->actingAs(V1::user(1));

    $this->deleteJson(V1::url('drives/'.$this->driveId), [], ($this->tag)())->assertOk()->assertJsonPath('data.status', 'deleting');
    $this->getJson(V1::url('drives/'.$this->driveId))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
});

it('requires the drive tag: 428 without it or with *, 412 stale_write once another change moved the version', function (string $method, string $path): void {
    $this->actingAs(V1::user(1));
    $stale = ($this->tag)();
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $stale)->assertOk();
    $this->postJson(V1::url('drives/'.$this->driveId.'/unarchive'), [], ($this->tag)())->assertOk();

    $this->json($method, V1::url('drives/'.$this->driveId.$path))->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $this->json($method, V1::url('drives/'.$this->driveId.$path), [], ['If-Match' => '*'])->assertStatus(428);
    $this->json($method, V1::url('drives/'.$this->driveId.$path), [], $stale)
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write')
        ->assertJsonPath('meta.aggregate', 'drive.drive');

    expect(Drive::query()->whereKey($this->driveId)->value('version'))->toBe(2);
})->with([
    'archive' => ['POST', '/archive'],
    'unarchive' => ['POST', '/unarchive'],
    'delete' => ['DELETE', ''],
]);

it('lets one of two clients with the same tag archive and refuses the other', function (): void {
    $this->actingAs(V1::user(1));
    $tag = ($this->tag)();

    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $tag)->assertOk();
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $tag)->assertStatus(412)->assertJsonPath('code', 'stale_write');
});

it('replays an archive under its key although its tag went stale, and refuses another body', function (): void {
    $this->actingAs(V1::user(1));
    $headers = [...($this->tag)(), 'Idempotency-Key' => 'archive-1'];
    $first = $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $headers)->assertOk();

    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.version', $first->json('data.version'));
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), ['note' => 'other'], $headers)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');

    expect(Drive::query()->whereKey($this->driveId)->value('version'))->toBe(1);
});
