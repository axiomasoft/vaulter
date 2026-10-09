<?php

declare(strict_types=1);

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Types\RichTextDocumentType;

/*
 * `DocumentExporter::export()` takes the OPAQUE `DocumentContent`, so an exporter must
 * narrow itself. A non-TipTap content is REFUSED with a named exception, not rendered as
 * an empty document. The registry keeps such content away from the built-in exporters
 * (they list only TipTap-backed types); this guard covers a direct call or a host type
 * listed by mistake.
 */
it('the html exporter refuses a non-TipTap DocumentContent instead of rendering it', function (): void {
    $opaque = OpaqueContent::fromArray(['sheets' => ['A1' => 'value']]);

    expect(fn () => app(HtmlExporter::class)->export($opaque, new RichTextDocumentType))
        ->toThrow(UnsupportedDocumentContentException::class, 'OpaqueContent');
});

it('the markdown exporter refuses a non-TipTap DocumentContent instead of rendering it', function (): void {
    $opaque = OpaqueContent::fromArray(['sheets' => ['A1' => 'value']]);

    expect(fn () => app(MarkdownExporter::class)->export($opaque, new RichTextDocumentType))
        ->toThrow(UnsupportedDocumentContentException::class, 'OpaqueContent');
});

it('both built-in exporters still render TipTap content through the widened signature', function (): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Still exported']],
        ]],
    ]);

    expect(app(HtmlExporter::class)->export($content, new RichTextDocumentType))->toContain('Still exported')
        ->and(app(MarkdownExporter::class)->export($content, new RichTextDocumentType))->toContain('Still exported');
});
