<?php

declare(strict_types=1);

use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

it('declares each built-in exporter format, mime type and extension', function (): void {
    $html = app(HtmlExporter::class);
    $markdown = app(MarkdownExporter::class);

    expect([$html->key(), $html->format(), $html->mimeType(), $html->extension()])->toBe(['vaulter/html', 'html', 'text/html', 'html'])
        ->and([$markdown->key(), $markdown->format(), $markdown->mimeType(), $markdown->extension()])->toBe(['vaulter/markdown', 'markdown', 'text/markdown', 'md'])
        ->and($html->documentTypes())->toBe(['vaulter/rich-text', 'vaulter/markdown']);
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
