<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Tests\Http\DriveV1;

/**
 * The node tree over v1: create, list (cursor pages, no totals), show, rename, move,
 * copy, search, abilities, shared-with-me — every node as the caller's representation.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

it('creates a folder and lists the root in a cursor page without a total', function (): void {
    $created = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Docs'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'folder')
        ->assertJsonPath('data.presentation', null)
        ->assertJsonPath('data.parent_id', $this->drive->root()->id);
    expect($created->headers->get('ETag'))->toBe($created->json('data.etag'));

    V1::folder($this->drive, 'Media');
    $page = $this->getJson(V1::url('drives/'.$this->driveId.'/nodes?per_page=1'))->assertOk()->assertJsonCount(1, 'data');
    expect($page->json('meta'))->toHaveKeys(['next_cursor', 'per_page'])->not->toHaveKey('total')
        ->and($page->json('meta.per_page'))->toBe(1);

    $next = $this->getJson(V1::url('drives/'.$this->driveId.'/nodes?per_page=1&cursor='.urlencode($page->json('meta.next_cursor'))))->assertOk();
    expect($next->json('data.0.id'))->not->toBe($page->json('data.0.id'));

    $this->getJson(V1::url('drives/'.$this->driveId.'/nodes?per_page=500'))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('lists a named folder and shows a node with the v1 fields only', function (): void {
    $folder = V1::folder($this->drive, 'Docs');
    $file = V1::file($this->drive, 'a.txt', 'abc', parent: $folder->id);

    $this->getJson(V1::url('drives/'.$this->driveId.'/nodes?parent='.$folder->id))->assertOk()->assertJsonPath('data.0.id', $file->id);

    $node = $this->getJson(V1::url('nodes/'.$file->id))->assertOk()->json('data');
    expect($node['id'])->toBeString()
        ->and($node['drive_id'])->toBe($this->driveId)
        ->and($node)->not->toHaveKeys(['storage_id', 'file_id', 'current_version_id', 'handler', 'is_pinned', 'pinned', 'position', 'owned_by'])
        ->and(array_keys($node['abilities']))->toHaveCount(12)
        ->and($node['file']['revision_number'])->toBe(1);
});

it('renames and moves with If-Match and copies without it', function (): void {
    $file = V1::file($this->drive, 'old.txt');
    $target = V1::folder($this->drive, 'Target');

    $renamed = $this->patchJson(V1::url('nodes/'.$file->id), ['name' => 'new.txt'], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertOk()->assertJsonPath('data.name', 'new.txt');
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $target->id], ['If-Match' => $renamed->headers->get('ETag')])
        ->assertOk()->assertJsonPath('data.parent_id', $target->id);

    $this->postJson(V1::url('nodes/'.$file->id.'/copy'), ['destination_id' => $target->id])
        ->assertCreated()->assertJsonPath('data.name', 'new (2).txt');
    $this->postJson(V1::url('nodes/'.$file->id.'/copy'), ['destination_id' => $target->id, 'name' => 'named.txt'])
        ->assertCreated()->assertJsonPath('data.name', 'named.txt');
});

it('refuses a move onto a taken name unless on_conflict=rename', function (): void {
    $file = V1::file($this->drive, 'same.txt');
    $target = V1::folder($this->drive, 'Target');
    V1::file($this->drive, 'same.txt', parent: $target->id);

    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $target->id], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertStatus(409);
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $target->id, 'on_conflict' => 'rename'], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertOk()->assertJsonPath('data.name', 'same (2).txt');
});

it('searches a drive by name', function (): void {
    V1::file($this->drive, 'quarterly-report.txt');
    V1::file($this->drive, 'notes.txt');

    $this->getJson(V1::url('drives/'.$this->driveId.'/search?q=report'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'quarterly-report.txt');
    $this->getJson(V1::url('drives/'.$this->driveId.'/search'))->assertStatus(422);
});

it('shows abilities and explains them only to a manager', function (): void {
    $file = V1::file($this->drive, 'x.txt');
    AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::READER, subjectId: 2);

    $this->getJson(V1::url('nodes/'.$file->id.'/abilities?explain=edit'))
        ->assertOk()
        ->assertJsonPath('data.abilities.manage', true)
        ->assertJsonPath('data.explain.ability', 'edit')
        ->assertJsonPath('data.explain.allowed', true);

    $this->actingAs(V1::user(2));
    $this->getJson(V1::url('nodes/'.$file->id.'/abilities'))
        ->assertOk()
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.edit', false)
        ->assertJsonPath('data.explain', null);
    $this->getJson(V1::url('nodes/'.$file->id.'/abilities?explain=edit'))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->getJson(V1::url('nodes/'.$file->id.'/abilities?explain=fly'))->assertStatus(422);
});

it('lists what was shared with the caller across drives, with its abilities', function (): void {
    $file = V1::file($this->drive, 'for-two.txt');
    AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));

    $this->getJson(V1::url('shared'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $file->id)
        ->assertJsonPath('data.0.abilities.view', true)
        ->assertJsonPath('data.0.abilities.edit', false)
        ->assertJsonPath('meta.next_cursor', null);
});

it('refuses the shared listing with 403 profile_restriction when grants are off', function (): void {
    config()->set('vaulter.features.grants', false);

    $this->getJson(V1::url('shared'))->assertForbidden()->assertJsonPath('code', 'profile_restriction');
});
