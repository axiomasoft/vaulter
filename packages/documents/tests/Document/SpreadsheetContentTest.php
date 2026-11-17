<?php

declare(strict_types=1);

use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The non-rich-text document-content path: the spreadsheet document type
 * decodes its content with the opaque JSON codec, so a workbook is stored VERBATIM —
 * the TipTap {"type":"doc"} constraint applies to rich-text documents only. The 0.x
 * create field `type` selects the spreadsheet type.
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

it('creates a spreadsheet document from its type key', function (): void {
    enableSpreadsheets();
    $drive = spreadsheetStorage()->getKey();

    $workbook = ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]];

    // Naming the spreadsheet type selects it; the response presents it.
    $created = $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Budget.xlsx',
        'type' => 'vaulter/spreadsheet',
        'content' => $workbook,
    ])->assertCreated();

    $created->assertJsonPath('data.presentation.document_type', 'vaulter/spreadsheet')
        ->assertJsonPath('data.presentation.viewer.key', 'vaulter/univer')
        ->assertJsonPath('data.presentation.capabilities.collaborative', false);
    $nodeId = $created->json('data.id');

    // A document is presented by its type, so its node carries the document mime.
    $node = app(DriveOptions::class)->model('node')::query()->findOrFail($nodeId);
    expect($node->mime_type)->toBe(CreateDocumentInput::NODE_MIME);

    // Its v1 head content round-trips the workbook verbatim (the opaque path, not TipTap).
    $this->getJson(Docs::url('documents/'.$nodeId.'/content'))
        ->assertOk()
        ->assertJsonPath('data.content.id', 'wb1')
        ->assertJsonPath('data.content.sheets.s1.name', 'Sheet1');
});

it('autosaves an opaque Univer workbook on a spreadsheet document', function (): void {
    enableSpreadsheets();
    $drive = spreadsheetStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Budget.xlsx',
        'type' => 'vaulter/spreadsheet',
    ])->assertCreated()->json('data.id');

    // A Univer workbook snapshot is NOT a {"type":"doc"} document; the opaque path accepts it.
    $workbook = ['id' => 'wb2', 'sheets' => ['s1' => ['name' => 'Q3', 'cellData' => []]]];

    Docs::put($nodeId, $workbook)
        ->assertOk()
        ->assertJsonPath('data.content.sheets.s1.name', 'Q3');

    $this->getJson(Docs::url('documents/'.$nodeId.'/content'))
        ->assertOk()
        ->assertJsonPath('data.content.sheets.s1.name', 'Q3');
});

it('rejects a spreadsheet create when the spreadsheet type is not registered', function (): void {
    // SpreadsheetDocumentType is not in vaulter-documents.types by default → 422 unknown type.
    $drive = spreadsheetStorage()->getKey();

    $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Budget.xlsx',
        'type' => 'vaulter/spreadsheet',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_document_type');
});

it('still creates a rich-text (tiptap) node and enforces {"type":"doc"} when no type is given', function (): void {
    $drive = spreadsheetStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Notes',
        'content' => ['type' => 'doc', 'content' => []],
    ])
        ->assertCreated()
        ->assertJsonPath('data.presentation.document_type', 'vaulter/rich-text')
        ->json('data.id');

    // A workbook-shaped payload on a rich-text node must be rejected — the type:doc rule stands.
    Docs::put($nodeId, ['id' => 'wb1'])->assertStatus(422);
});

it('rejects a non-doc create payload on a rich-text node (no type)', function (): void {
    $drive = spreadsheetStorage()->getKey();

    // Without a type the node is rich-text, so the create content must be a TipTap doc.
    $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Notes',
        'content' => ['id' => 'wb1'],
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_document_content');
});
