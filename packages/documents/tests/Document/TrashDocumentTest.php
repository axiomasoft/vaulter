<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Fixtures\TestUser;

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

    $nodeId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/documents'), [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
        ]],
    ])->assertCreated()->json('data.id');

    $nodeBase = Docs::url('documents/'.$nodeId);

    // Control: live document reads fine.
    $this->getJson($nodeBase.'/content')->assertOk();

    app(VaulterDatabase::class)->write(fn () => Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save());

    // Same actor, same still-live grant — trashed content is unreachable.
    $this->getJson($nodeBase.'/content')->assertNotFound();
});

it('404s on exporting a trashed document node, F-P1.12-01', function (): void {
    $storage = trashDocumentStorage();

    $nodeId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/documents'), [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
        ]],
    ])->assertCreated()->json('data.id');

    $nodeBase = Docs::url('documents/'.$nodeId);

    $this->getJson($nodeBase.'/export/html')->assertOk();

    app(VaulterDatabase::class)->write(fn () => Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save());

    $this->getJson($nodeBase.'/export/html')->assertNotFound();
});

it('404s on listing revision history of a trashed node, F-P1.12-01', function (): void {
    $storage = trashDocumentStorage();

    $nodeId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/documents'), [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');

    app(VaulterDatabase::class)->write(fn () => Node::query()->find($nodeId)->forceFill(['trashed_at' => now()])->save());

    $this->getJson(Docs::url('documents/'.$nodeId.'/revisions'))->assertNotFound();
});
