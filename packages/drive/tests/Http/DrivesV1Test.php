<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/** The drive resource and its lifecycle over v1: show, archive, unarchive, delete. */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
});

it('shows a drive with its owner, root, status and quota, and nothing internal', function (): void {
    $this->actingAs(V1::user(1));

    $response = $this->getJson(V1::url('drives/'.$this->driveId))
        ->assertOk()
        ->assertJsonPath('data.id', $this->driveId)
        ->assertJsonPath('data.root_node_id', $this->drive->root()->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.used_bytes', 0);

    expect(array_keys($response->json('data')))->toBe(['id', 'owner', 'profile', 'name', 'root_node_id', 'status', 'quota_bytes', 'used_bytes', 'created_at'])
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
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'))->assertForbidden();

    $this->actingAs(V1::user(1));
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'))->assertOk()->assertJsonPath('data.status', 'archived');
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'read-only'])->assertStatus(409);
    $this->postJson(V1::url('drives/'.$this->driveId.'/unarchive'))->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'writable'])->assertCreated();
});

it('starts deleting a drive, after which it is gone for everyone', function (): void {
    $this->actingAs(V1::user(1));

    $this->deleteJson(V1::url('drives/'.$this->driveId))->assertOk()->assertJsonPath('data.status', 'deleting');

    $this->getJson(V1::url('drives/'.$this->driveId))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
});
