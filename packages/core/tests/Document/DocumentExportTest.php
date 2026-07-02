<?php

declare(strict_types=1);

use Vaulter\DocDrive\Actions\CreateDocumentNodeAction;
use Vaulter\DocDrive\Data\CreateDocumentNodeData;
use Vaulter\DocDrive\Documents\NodeExportService;
use Vaulter\Tests\Document\DocumentTestHelpers;

/**
 * The node-attached export path (§7 / M4a): a document node routes through the
 * handler → exporter (the existing Html/Markdown exporters) by format.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    $this->node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $this->drive->storage(),
        name: 'Report',
        content: DocumentTestHelpers::content('Exportable body'),
        parent: $this->drive->root(),
    ));
});

it('exports a document node to HTML through the registered exporter', function (): void {
    $export = app(NodeExportService::class)->export($this->node, 'html');

    expect($export->format)->toBe('html')
        ->and($export->mimeType)->toBe('text/html')
        ->and($export->content)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.html');
});

it('exports a document node to Markdown through the registered exporter', function (): void {
    $export = app(NodeExportService::class)->export($this->node, 'markdown');

    expect($export->format)->toBe('markdown')
        ->and($export->content)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.md');
});

it('advertises the registered export formats for an editable document node', function (): void {
    $formats = app(NodeExportService::class)->availableFormats($this->node);

    expect($formats)->toContain('html')->toContain('markdown');
});
