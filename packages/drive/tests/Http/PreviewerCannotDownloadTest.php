<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/** A previewer sees the file and its metadata but never gets its bytes. */
uses(RefreshDatabase::class);

it('shows the node to a previewer, with download off, and refuses the bytes with 403', function (string $mode): void {
    Storage::fake('local');
    config()->set('vaulter.downloads.delivery_mode', $mode);
    $drive = V1::drive(userId: 1);
    $file = V1::file($drive, 'preview.txt', 'preview only');
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::PREVIEWER, subjectId: 5);
    $this->actingAs(V1::user(5));

    $this->getJson(V1::url('nodes/'.$file->id))
        ->assertOk()
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.download', false);

    $this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_denied');
    $this->get(V1::url('files/'.$file->id.'/revisions/'.$revision->getKey().'/download'), ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs(V1::user(6));
    $this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])->assertNotFound();
})->with(['strict', 'direct_signed']);
