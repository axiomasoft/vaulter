<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Item-safety (P2.2 / F-P1.12-01): a document node sitting in the drive trash
 * (`trashed_at`) must be unreadable/unexportable through the identity document
 * surface, even when the actor's ACL grant on it is still live — symmetric with the
 * DownloadController fix on the file surface.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function trashDocumentStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('404s on reading the content of a trashed document node, F-P1.12-01', function (): void {
    $storage = trashDocumentStorage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
        ]],
    ])->assertCreated()->json('data.id');

    $nodeBase = 'api/vaulter/nodes/'.$nodeId;

    // Control: live document reads fine.
    $this->getJson($nodeBase.'/content')->assertOk();

    Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save();

    // Same actor, same still-live grant — trashed content is unreachable.
    $this->getJson($nodeBase.'/content')->assertNotFound();
});

it('404s on exporting a trashed document node, F-P1.12-01', function (): void {
    $storage = trashDocumentStorage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
        ]],
    ])->assertCreated()->json('data.id');

    $nodeBase = 'api/vaulter/nodes/'.$nodeId;

    $this->getJson($nodeBase.'/export?format=html')->assertOk();

    Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save();

    $this->getJson($nodeBase.'/export?format=html')->assertNotFound();
});

it('404s on listing document-version history of a trashed node, F-P1.12-01', function (): void {
    $storage = trashDocumentStorage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');

    Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save();

    $this->getJson('api/vaulter/nodes/'.$nodeId.'/document-versions')->assertNotFound();
});
