<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Operations\OperationContext;

/**
 * The node-attached export path (§7 / M4a): a document node routes through the
 * handler → exporter (the existing Html/Markdown exporters) by format.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    $this->node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Report',
        content: DocumentTestHelpers::content('Exportable body'),
        parent: $this->drive->root(),
    ));
});

it('exports a document node to HTML through the registered exporter', function (): void {
    $export = app(NodeExportService::class)->export(OperationContext::system($this->node->storage()->firstOrFail(), 'export fixture'), $this->node, 'html');

    expect($export->format)->toBe('html')
        ->and($export->mimeType)->toBe('text/html')
        ->and($export->content)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.html');
});

it('exports a document node to Markdown through the registered exporter', function (): void {
    $export = app(NodeExportService::class)->export(OperationContext::system($this->node->storage()->firstOrFail(), 'export fixture'), $this->node, 'markdown');

    expect($export->format)->toBe('markdown')
        ->and($export->content)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.md');
});

it('advertises the registered export formats for an editable document node', function (): void {
    $formats = app(NodeExportService::class)->availableFormats($this->node);

    expect($formats)->toContain('html')->toContain('markdown');
});
