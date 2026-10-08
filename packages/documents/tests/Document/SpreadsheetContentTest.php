<?php

declare(strict_types=1);

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The non-rich-text document-content path (§7 level 2): the spreadsheet document type
 * decodes its content with the opaque JSON codec, so a workbook is stored VERBATIM —
 * the TipTap {"type":"doc"} constraint applies to rich-text documents only. The 0.x
 * create field `mime` still selects the spreadsheet type (PLAN2.P4.3).
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function spreadsheetStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/**
 * What the next request sees once the host lists SpreadsheetDocumentType in
 * vaulter-documents.types (it is not registered by default).
 */
function enableSpreadsheets(): void
{
    $types = new DocumentTypeRegistry(app());
    foreach ([RichTextDocumentType::class, MarkdownDocumentType::class, SpreadsheetDocumentType::class] as $type) {
        $types->register($type);
    }
    $types->freeze();
    app()->instance(DocumentTypeRegistry::class, $types);
}

it('creates a spreadsheet document from the 0.x mime field', function (): void {
    enableSpreadsheets();
    $base = 'api/vaulter/storages/'.spreadsheetStorage()->getKey();

    $workbook = ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]];

    // Passing the xlsx mime selects the spreadsheet type; the response presents it.
    $created = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => XLSX_MIME,
        'content' => $workbook,
    ])->assertCreated();

    $created->assertJsonPath('data.handler.document_type', 'vaulter/spreadsheet')
        ->assertJsonPath('data.handler.ui.key', 'vaulter/univer')
        ->assertJsonPath('data.handler.capabilities.collaborative', false);
    $nodeId = $created->json('data.id');

    // A document is presented by its type, so its node carries the document mime.
    $node = app(VaulterConfig::class)->model('node')::query()->findOrFail($nodeId);
    expect($node->mime_type)->toBe(CreateDocumentInput::NODE_MIME);

    // Its v1 head content round-trips the workbook verbatim (the opaque path, not TipTap).
    $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')
        ->assertOk()
        ->assertJsonPath('data.content.id', 'wb1')
        ->assertJsonPath('data.content.sheets.s1.name', 'Sheet1');
});

it('autosaves an opaque Univer workbook on a spreadsheet document', function (): void {
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

it('rejects a spreadsheet create when the spreadsheet type is not registered', function (): void {
    // SpreadsheetDocumentType is not in vaulter-documents.types by default → 422 on mime.
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
        ->assertJsonPath('data.handler.document_type', 'vaulter/rich-text')
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
