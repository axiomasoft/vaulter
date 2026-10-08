<?php

declare(strict_types=1);

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/*
 * VLT-CANON P0.5 — the export read path (`NodeExportService::contentOf()`) is now
 * kind-neutral (closing the P0.2/P0.3/P0.4 Pending Work item): a non-rich-text
 * node's head resolves as its own OpaqueContent shape instead of decaying
 * to an EMPTY TipTap doc. The Html/Markdown exporters' existing narrowing guard
 * ({@see UnsupportedDocumentContentException}) now actually reaches a non-rich-text
 * node, refusing the export instead of silently rendering a blank document.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    // The host lists the spreadsheet type in vaulter-documents.types.
    $types = new DocumentTypeRegistry(app());
    foreach ([RichTextDocumentType::class, MarkdownDocumentType::class, SpreadsheetDocumentType::class] as $type) {
        $types->register($type);
    }
    $types->freeze();
    app()->instance(DocumentTypeRegistry::class, $types);
});

function univerStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

const RIVAL_XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

it('refuses to export a non-rich-text (Univer) node to HTML instead of rendering it blank', function (): void {
    $base = 'api/vaulter/storages/'.univerStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => RIVAL_XLSX_MIME,
        'content' => ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]],
    ])->assertCreated()->json('data.id');

    $node = app(VaulterConfig::class)->model('node')::query()->findOrFail($nodeId);

    expect(fn () => app(NodeExportService::class)->export(OperationContext::system($node->storage()->firstOrFail(), 'export fixture'), $node, 'html'))
        ->toThrow(UnsupportedDocumentContentException::class);
});

it('refuses to export a non-rich-text (Univer) node to Markdown instead of rendering it blank', function (): void {
    $base = 'api/vaulter/storages/'.univerStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Budget.xlsx',
        'mime' => RIVAL_XLSX_MIME,
        'content' => ['id' => 'wb1', 'sheets' => ['s1' => ['name' => 'Sheet1', 'cellData' => []]]],
    ])->assertCreated()->json('data.id');

    $node = app(VaulterConfig::class)->model('node')::query()->findOrFail($nodeId);

    expect(fn () => app(NodeExportService::class)->export(OperationContext::system($node->storage()->firstOrFail(), 'export fixture'), $node, 'markdown'))
        ->toThrow(UnsupportedDocumentContentException::class);
});
