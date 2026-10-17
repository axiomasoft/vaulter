<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/** Stop and restore inherited grants at a node; both answer the node with its new tag. */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.grants', true);
    $this->drive = V1::drive(userId: 1);
    $this->folder = V1::folder($this->drive, 'restricted');
    $this->actingAs(V1::user(1));
});

it('restricts inheritance (empty by default) and restores it', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 1);

    $restricted = $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->folder->id)
        ->assertJsonPath('data.inherits_access', false);
    expect($restricted->headers->get('ETag'))->toBe($restricted->json('data.etag'))
        ->and($restricted->headers->get('Cache-Control'))->toContain('no-store');

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restore'))
        ->assertOk()
        ->assertJsonPath('data.inherits_access', true);
});

it('answers the node with all abilities off when the restriction removed the caller\'s own access', function (): void {
    $locked = $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'))
        ->assertOk()
        ->assertJsonPath('data.inherits_access', false);

    expect(array_unique(array_values($locked->json('data.abilities'))))->toBe([false]);
    $this->getJson(V1::url('nodes/'.$this->folder->id.'/grants'))->assertNotFound();
});

it('can keep copies of the inherited grants', function (): void {
    AccessTestHelpers::grant($this->drive->root(), RoleRegistry::READER, subjectId: 8);

    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), ['mode' => 'copy_current'])->assertOk();

    expect($this->drive->actingAs(V1::user(8))->nodes()->find($this->folder->id)->id)->toBe($this->folder->id);
});

it('rejects an unknown mode and needs Manage', function (): void {
    $this->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'), ['mode' => 'copy'])->assertUnprocessable();

    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::WRITER, subjectId: 2);
    $this->actingAs(V1::user(2))->postJson(V1::url('nodes/'.$this->folder->id.'/inheritance/restrict'))->assertForbidden();
});
