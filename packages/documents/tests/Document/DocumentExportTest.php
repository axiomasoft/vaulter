<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * The node-attached export path (§7 / M4a): a document node routes through the
 * handler → exporter (the existing Html/Markdown exporters) by format.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    $this->node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->drive->model()), new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Report',
        content: DocumentTestHelpers::content('Exportable body'),
        parent: DriveTestHelpers::model($this->drive->root()),
    ));
});

it('exports a document node to HTML through the registered exporter', function (): void {
    $export = app(ExportDocumentAction::class)->execute(DriveContext::system($this->node->storage()->firstOrFail(), 'export fixture'), new ExportDocumentInput($this->node, 'html'));

    expect($export->format)->toBe('html')
        ->and($export->mimeType)->toBe('text/html')
        ->and($export->bytes)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.html');
});

it('exports a document node to Markdown through the registered exporter', function (): void {
    $export = app(ExportDocumentAction::class)->execute(DriveContext::system($this->node->storage()->firstOrFail(), 'export fixture'), new ExportDocumentInput($this->node, 'markdown'));

    expect($export->format)->toBe('markdown')
        ->and($export->bytes)->toContain('Exportable body')
        ->and($export->filename)->toBe('Report.md');
});

it('advertises the registered export formats for an editable document node', function (): void {
    $formats = app(DocumentExporterRegistry::class)->formatsFor('vaulter/rich-text');

    expect($formats)->toContain('html')->toContain('markdown');
});
