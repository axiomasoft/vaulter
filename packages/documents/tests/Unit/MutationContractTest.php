<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

it('declares each built-in exporter format, mime type and extension', function (): void {
    $html = app(HtmlExporter::class);
    $markdown = app(MarkdownExporter::class);

    expect([$html->key(), $html->format(), $html->mimeType(), $html->extension()])->toBe(['vaulter/html', 'html', 'text/html', 'html'])
        ->and([$markdown->key(), $markdown->format(), $markdown->mimeType(), $markdown->extension()])->toBe(['vaulter/markdown', 'markdown', 'text/markdown', 'md'])
        ->and($html->documentTypes())->toBe(['vaulter/rich-text', 'vaulter/markdown']);
});

it('returns the configured document-owned model classes', function (): void {
    expect(app(DocumentsOptions::class)->model('node_comment'))->toBe(NodeComment::class)
        ->and(app(DocumentsOptions::class)->model('document_revision'))->toBe(DocumentRevision::class);
});

it('rejects configured model classes outside the document-owned inheritance seams', function (): void {
    config()->set('vaulter-documents.models.node_comment', stdClass::class);

    expect(fn () => app(DocumentsOptions::class))
        ->toThrow(InvalidConfiguration::class, '[Vaulter] Model vaulter-documents.models.node_comment must be a class extending '.NodeComment::class.'.');

    config()->set('vaulter-documents.models.document_revision', stdClass::class);

    expect(fn () => app(DocumentsOptions::class))
        ->toThrow(InvalidConfiguration::class, '[Vaulter] Model vaulter-documents.models.document_revision must be a class extending '.DocumentRevision::class.'.');
});
