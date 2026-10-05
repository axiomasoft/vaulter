<?php

declare(strict_types=1);

use Vaulter\Documents\Engines\Handlers\UniverHandler;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Support\Config;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The non-rich-text document-content path (§7 level 2): a handler that declares
 * richText=false (the spreadsheet UniverHandler) has its content stored VERBATIM as an
 * OpaqueDocumentContent — the write path drops the TipTap {"type":"doc"} constraint for
 * that node, while KEEPING it for rich-text (tiptap) nodes. The create path is
 * HANDLER-AWARE: a `mime` on POST resolves the format's handler, so a spreadsheet node
 * is BORN as one (no post-hoc mime patching).
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function spreadsheetStorage(): Drive
{
    return Vaulter::for(DriveTestHelpers::host())->model();
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** Register the feature-flagged spreadsheet handler at runtime (it is off by default). */
function enableSpreadsheets(): void
{
    Vaulter::handlers()->register(UniverHandler::class);
}

it('creates a spreadsheet node handler-aware — the node is born on the Univer handler', function (): void {
    enableSpreadsheets();
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $workbook = ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]];

    // Passing the xlsx mime routes the create to the Univer handler — no forceFill, no
    // post-create mime patch; the create response already resolves handler=univer.
    $created = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => XLSX_MIME,
        'content' => $workbook,
    ])->assertCreated();

    $created->assertJsonPath('data.handler.id', 'vaulter/univer');
    $nodeId = $created->json('data.id');

    // The node was stamped with the xlsx mime so a fresh read re-resolves the same handler.
    $node = Config::nodeModel()::query()->findOrFail($nodeId);
    expect($node->mime_type)->toBe(XLSX_MIME);

    // Its v1 head content round-trips the workbook verbatim (the opaque path, not TipTap).
    $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')
        ->assertOk()
        ->assertJsonPath('data.content.id', 'wb1')
        ->assertJsonPath('data.content.sheets.s1.name', 'Sheet1');
});

it('autosaves an opaque Univer workbook on a handler-aware spreadsheet node', function (): void {
    enableSpreadsheets();
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => XLSX_MIME,
    ])->assertCreated()->json('data.id');

    // A Univer workbook snapshot is NOT a {"type":"doc"} document; the opaque path accepts it.
    $workbook = ['id' => 'wb2', 'sheets' => ['s1' => ['name' => 'Q3', 'cellData' => []]]];

    $this->patchJson('api/vaulter/nodes/'.$nodeId.'/content', ['content' => $workbook])
        ->assertOk()
        ->assertJsonPath('data.is_autosave', true);

    $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')
        ->assertOk()
        ->assertJsonPath('data.content.sheets.s1.name', 'Q3');
});

it('rejects a spreadsheet create when the format has no editable handler', function (): void {
    // The spreadsheets feature is OFF (handler NOT registered): xlsx falls to the
    // download-only handler, which cannot back an editable document node → 422.
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => XLSX_MIME,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mime');
});

it('still creates a rich-text (tiptap) node and enforces {"type":"doc"} when no mime is given', function (): void {
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Notes',
        'content' => ['type' => 'doc', 'content' => []],
    ])
        ->assertCreated()
        ->assertJsonPath('data.handler.id', 'vaulter/tiptap')
        ->json('data.id');

    // A workbook-shaped payload on a rich-text node must be rejected — the type:doc rule stands.
    $this->patchJson('api/vaulter/nodes/'.$nodeId.'/content', ['content' => ['id' => 'wb1']])
        ->assertStatus(422);
});

it('rejects a non-doc create payload on a rich-text node (no mime)', function (): void {
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    // Without a mime the node is rich-text, so the create content must be a TipTap doc.
    $this->postJson($base.'/documents', [
        'name' => 'Notes',
        'content' => ['id' => 'wb1'],
    ])->assertStatus(422)->assertJsonValidationErrors('content.type');
});
