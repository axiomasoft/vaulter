<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Stop and restore inherited grants at a node; both answer the node with its new tag. Both are conditional on the
 * node's access tag from grants.index (If-Match: 428 without it, 412 stale_write once the access changed) and take an
 * Idempotency-Key; neither moves the node's sequence.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.features.grants', true);
    $this->drive = V1::drive(userId: 1);
    $this->folder = V1::folder($this->drive, 'restricted');
    $this->actingAs(V1::user(1));
    $this->access = fn (): array => ['If-Match' => (string) $this->getJson(V1::url('nodes/'.$this->folder->id.'/grants'))->assertOk()->json('etag')];
});

it('restricts inheritance (empty by default) and restores it', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);
    $sequence = DriveTestHelpers::model($this->folder)->sequence;

    $restricted = $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], ($this->access)())
        ->assertOk()
        ->assertJsonPath('data.id', $this->folder->id)
        ->assertJsonPath('data.inherits_access', false);
    expect($restricted->headers->get('ETag'))->toBe($restricted->json('data.etag'))
        ->and($restricted->headers->get('Cache-Control'))->toContain('no-store');

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restore'), [], ($this->access)())
        ->assertOk()
        ->assertJsonPath('data.inherits_access', true);

    expect(DriveTestHelpers::model($this->folder)->sequence)->toBe($sequence)
        ->and(DriveTestHelpers::model($this->folder)->access_version)->toBe(2);
});

it('answers the node with all abilities off when the restriction removed the caller\'s own access', function (): void {
    $locked = $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], ($this->access)())
        ->assertOk()
        ->assertJsonPath('data.inherits_access', false);

    expect(array_unique(array_values($locked->json('data.abilities'))))->toBe([false]);
    $this->getJson(V1::url('nodes/'.$this->folder->id.'/grants'))->assertNotFound();
});

it('can keep copies of the inherited grants', function (): void {
    AccessTestHelpers::grant($this->drive->root(), RoleRegistry::READER, subjectId: 8);

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), ['mode' => 'copy_current'], ($this->access)())->assertOk();

    expect($this->drive->actingAs(V1::user(8))->nodes()->find($this->folder->id)->id)->toBe($this->folder->id);
});

it('rejects an unknown mode and needs Manage', function (): void {
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), ['mode' => 'copy'], ($this->access)())->assertUnprocessable();
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::WRITER, subjectId: 2);

    $this->actingAs(V1::user(2))->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'))->assertForbidden();
});

it('refuses a restriction under an access tag that a grant made stale', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);
    $stale = ($this->access)();
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/grants'), ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => 5], 'role' => RoleRegistry::READER])->assertCreated();

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], $stale)
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write');

    expect(DriveTestHelpers::model($this->folder)->inherit_grants)->toBeTrue();
});

it('replays a restriction under its key although its tag went stale', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);
    $headers = [...($this->access)(), 'Idempotency-Key' => 'restrict-1'];
    $first = $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], $headers)->assertOk();

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), [], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.etag', $first->json('data.etag'));

    expect(DriveTestHelpers::model($this->folder)->access_version)->toBe(1);
});
