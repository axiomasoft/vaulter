<?php

declare(strict_types=1);

use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Enums\Export\ExportFormat;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

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
    expect(app(DocumentsConfig::class)->model('node_comment'))->toBe(NodeComment::class)
        ->and(app(DocumentsConfig::class)->model('document_revision'))->toBe(DocumentRevision::class);
});

it('rejects configured model classes outside the document-owned inheritance seams', function (): void {
    config()->set('vaulter-documents.models.node_comment', stdClass::class);

    expect(fn () => app(DocumentsConfig::class)->model('node_comment'))
        ->toThrow(InvalidConfigurationException::class, 'must name a class implementing or extending '.NodeComment::class);

    config()->set('vaulter-documents.models.document_revision', stdClass::class);

    expect(fn () => app(DocumentsConfig::class)->model('document_revision'))
        ->toThrow(InvalidConfigurationException::class, 'must name a class implementing or extending '.DocumentRevision::class);
});
