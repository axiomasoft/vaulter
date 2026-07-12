<?php

declare(strict_types=1);

use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;

/*
 * VLT-CANON P0.2: `DocumentExporter::export()` takes the OPAQUE `DocumentContent`, so an
 * exporter can no longer assume TipTap by signature — it must NARROW itself. These two
 * pin the narrowing of the built-in exporters: a non-TipTap `DocumentContent` (the
 * spreadsheet path's `OpaqueDocumentContent`) is REFUSED with a named exception, not
 * rendered as an empty document.
 *
 * The guard is the only new behaviour P0.2 adds; without these tests an inverted
 * `instanceof` would stay green. It is currently reachable only through a CUSTOM
 * exporter or a direct call: the package's own `NodeExportService::contentOf()` still
 * coerces every version through `TipTapContent::decayFrom()` (making a non-TipTap
 * payload decay to an EMPTY TipTap doc rather than reach this guard). Making
 * `contentOf()` kind-neutral is out of P0.2's scope — see the plan's Pending Work.
 */
it('the html exporter refuses a non-TipTap DocumentContent instead of rendering it', function (): void {
    $opaque = OpaqueDocumentContent::fromArray(['sheets' => ['A1' => 'value']]);

    expect(fn () => app(HtmlExporter::class)->export($opaque))
        ->toThrow(UnsupportedDocumentContentException::class, 'OpaqueDocumentContent');
});

it('the markdown exporter refuses a non-TipTap DocumentContent instead of rendering it', function (): void {
    $opaque = OpaqueDocumentContent::fromArray(['sheets' => ['A1' => 'value']]);

    expect(fn () => app(MarkdownExporter::class)->export($opaque))
        ->toThrow(UnsupportedDocumentContentException::class, 'OpaqueDocumentContent');
});

it('both built-in exporters still render TipTap content through the widened signature', function (): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Still exported']],
        ]],
    ]);

    expect(app(HtmlExporter::class)->export($content))->toContain('Still exported')
        ->and(app(MarkdownExporter::class)->export($content))->toContain('Still exported');
});
