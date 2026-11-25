<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * Item-safety: a node sitting in the drive trash (`trashed_at`)
 * must be unreadable through the identity download surface too, symmetric with the
 * already-correct share channel (`ShareResolver::safeTargetNode`) — even when the
 * actor's ACL grant on it is still live.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function trashStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('404s on downloading the current head of a trashed node', function (): void {
    $storage = trashStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $nodeId = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('secret.txt', 'still readable?'),
    ])->assertCreated()->json('data.id');

    // Control: live node downloads fine.
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertOk();

    Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save();

    // Same actor, same still-live grant — the trash gate 404s before ACL runs.
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertNotFound();
});

it('404s on downloading a specific version of a trashed node', function (): void {
    $storage = trashStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $nodeId = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('v.txt', 'v1'),
    ])->assertCreated()->json('data.id');

    $versionId = Node::query()->find($nodeId)->file()->value('current_revision_id');

    $this->get('api/vaulter/v1/files/'.$nodeId.'/revisions/'.$versionId.'/download')->assertOk();

    Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save();

    $this->get('api/vaulter/v1/files/'.$nodeId.'/revisions/'.$versionId.'/download')->assertNotFound();
});

it('restore makes a trashed node downloadable again, no regression to the un-trash path', function (): void {
    $storage = trashStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $nodeId = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('r.txt', 'restore me'),
    ])->assertCreated()->json('data.id');

    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/trash', [], DriveV1::ifMatch($nodeId))->assertOk();
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertNotFound();

    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/restore', [], DriveV1::ifMatch($nodeId))->assertOk();
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertOk();
});
