<?php

declare(strict_types=1);

use Vaulter\Documents\DocumentsModels;
use Vaulter\Documents\Enums\Export\ExportFormat;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

it('maps every export format to its public value, mime type and extension', function (): void {
    expect(ExportFormat::values())->toBe(['html', 'markdown'])
        ->and(ExportFormat::Html->label())->toBe('HTML')
        ->and(ExportFormat::Html->mimeType())->toBe('text/html')
        ->and(ExportFormat::Html->extension())->toBe('html')
        ->and(ExportFormat::Markdown->label())->toBe('Markdown')
        ->and(ExportFormat::Markdown->mimeType())->toBe('text/markdown')
        ->and(ExportFormat::Markdown->extension())->toBe('md');
});

it('returns the configured document-owned model classes', function (): void {
    expect(DocumentsModels::comment())->toBe(NodeComment::class)
        ->and(DocumentsModels::documentVersion())->toBe(DocumentRevision::class);
});

it('rejects configured model classes outside the document-owned inheritance seams', function (): void {
    config()->set('vaulter.models.node_comment', stdClass::class);

    expect(fn () => DocumentsModels::comment())
        ->toThrow(RuntimeException::class, 'must extend '.NodeComment::class);

    config()->set('vaulter.models.node_document_version', stdClass::class);

    expect(fn () => DocumentsModels::documentVersion())
        ->toThrow(RuntimeException::class, 'must extend '.DocumentRevision::class);
});
