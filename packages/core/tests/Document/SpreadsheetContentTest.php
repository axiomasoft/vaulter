<?php

declare(strict_types=1);

use Vaulter\Documents\Handlers\UniverHandler;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Support\Config;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The non-rich-text document-content path (§7 level 2): a handler that declares
 * richText=false (the spreadsheet UniverHandler) has its content stored VERBATIM as an
 * OpaqueDocumentContent — the write path drops the TipTap {"type":"doc"} constraint for
 * that node, while KEEPING it for rich-text (tiptap) nodes.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    // The spreadsheet handler is feature-flagged off by default; register it at runtime so
    // an xlsx-mime node resolves to it instead of falling to DownloadOnly.
    Vaulter::registerHandler(UniverHandler::class);
});

function spreadsheetStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

it('stores an opaque Univer workbook on a node whose handler is non-rich-text', function (): void {
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    // Create the document node, then point it at the xlsx mime so the registry resolves it
    // to the Univer handler (the create action stamps the TipTap mime by default).
    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');

    $node = Config::nodeModel()::query()->findOrFail($nodeId);
    $node->forceFill(['mime_type' => XLSX_MIME, 'extension' => 'xlsx'])->save();

    // The handler now resolves to Univer (richText=false).
    $this->getJson('api/vaulter/nodes/'.$nodeId.'/handler')
        ->assertOk()
        ->assertJsonPath('data.id', 'univer');

    // A Univer workbook snapshot is NOT a {"type":"doc"} document; the opaque path accepts it.
    $workbook = ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]];

    $this->patchJson('api/vaulter/nodes/'.$nodeId.'/content', ['content' => $workbook])
        ->assertOk()
        ->assertJsonPath('data.is_autosave', true);

    // The stored head content round-trips the workbook verbatim.
    $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')
        ->assertOk()
        ->assertJsonPath('data.content.id', 'wb1')
        ->assertJsonPath('data.content.sheets.s1.name', 'Sheet1');
});

it('still enforces the TipTap {"type":"doc"} shape on a rich-text (tiptap) node', function (): void {
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Notes',
        'content' => ['type' => 'doc', 'content' => []],
    ])
        ->assertCreated()
        ->assertJsonPath('data.handler.id', 'tiptap')
        ->json('data.id');

    // A workbook-shaped payload on a rich-text node must be rejected — the type:doc rule stands.
    $this->patchJson('api/vaulter/nodes/'.$nodeId.'/content', ['content' => ['id' => 'wb1']])
        ->assertStatus(422);
});
