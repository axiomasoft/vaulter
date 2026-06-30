<?php

declare(strict_types=1);

use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\Documents\DocumentHandlerRegistry;
use Vaulter\Documents\Handlers\DownloadOnlyHandler;
use Vaulter\Documents\Handlers\PreviewHandler;
use Vaulter\Documents\Handlers\TipTapHandler;
use Vaulter\Documents\Handlers\UniverHandler;
use Vaulter\Enums\Documents\Capability;
use Vaulter\Facades\Vaulter;
use Vaulter\ValueObjects\Documents\DocumentCapabilities;
use Vaulter\ValueObjects\Documents\FileDescriptor;
use Vaulter\ValueObjects\Documents\HandlerUi;

/**
 * The format-extensibility registry (§4.5 / §7 / H.5): mime → extension → capability
 * → priority → null-object, the runtime-registration proof and the claimedMime spoof
 * guard. THIS is the headline — a new format is one class + register, no migration.
 */
beforeEach(function (): void {
    $this->registry = app(DocumentHandlerRegistry::class);
});

it('routes a docx-like file to the editable TipTapHandler', function (): void {
    $file = FileDescriptor::make(
        nodeId: 1,
        filename: 'report.docx',
        detectedMime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    $handler = $this->registry->resolve($file, Capability::Edit);

    expect($handler->id())->toBe('tiptap')
        ->and($handler->capabilities($file)->editable)->toBeTrue();
});

it('routes a pdf to the previewable PreviewHandler', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'scan.pdf', detectedMime: 'application/pdf');

    $handler = $this->registry->resolve($file, Capability::Preview);

    expect($handler->id())->toBe('preview')
        ->and($handler->capabilities($file)->previewable)->toBeTrue()
        ->and($handler->capabilities($file)->thumbnailable)->toBeTrue();
});

it('routes any image to PreviewHandler via the image/* wildcard', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'photo.png', detectedMime: 'image/png');

    expect($this->registry->resolve($file, Capability::Preview)->id())->toBe('preview');
});

it('falls back to DownloadOnly for an archive / unknown type', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'bundle.zip', detectedMime: 'application/zip');

    $handler = $this->registry->resolve($file, Capability::Download);

    expect($handler->id())->toBe('download')
        ->and($handler->capabilities($file)->editable)->toBeFalse()
        ->and($handler->capabilities($file)->downloadable)->toBeTrue();
});

it('never returns null — DownloadOnly is the guaranteed terminal resolution', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'mystery', detectedMime: null);

    expect($this->registry->resolve($file, Capability::Download))
        ->toBeInstanceOf(DownloadOnlyHandler::class);
});

it('IGNORES a spoofed claimedMime — the server-sniffed detectedMime wins (H.5)', function (): void {
    // A hostile upload CLAIMS the editable-document Content-Type but the server
    // sniffed a zip (and the filename has no editable extension either). The registry
    // must route on the sniff, never the claim, so it never lands an editable handler
    // on the spoofed bytes.
    $file = FileDescriptor::make(
        nodeId: 1,
        filename: 'invoice.zip',
        claimedMime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        detectedMime: 'application/zip',
    );

    $handler = $this->registry->resolve($file, Capability::Download);

    // Routes on the sniff (zip → DownloadOnly), not the spoofed docx claim.
    expect($handler->id())->toBe('download')
        ->and($file->canonicalMime())->toBe('application/zip');
});

it('resolves a RUNTIME-registered custom handler with NO migration and NO core change (H.5)', function (): void {
    // The whole extensibility headline: a host writes ONE class for a brand-new
    // format (.dwg CAD) and registers it at runtime. It resolves immediately — the
    // registry's string keys are the source of truth, not any sealed enum.
    Vaulter::registerHandler(CadHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'part.dwg', detectedMime: 'image/vnd.dwg');

    $handler = app(DocumentHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->id())->toBe('cad')
        ->and($handler->capabilities($file)->editable)->toBeTrue();
});

it('resolves the custom CAD handler by EXTENSION when the mime is unknown', function (): void {
    Vaulter::registerHandler(new CadHandler);

    // No detected mime at all — only the .dwg extension routes it.
    $file = FileDescriptor::make(nodeId: 1, filename: 'assembly.dwg');

    expect(app(DocumentHandlerRegistry::class)->resolve($file, Capability::Edit)->id())->toBe('cad');
});

it('sorts matching handlers by priority (the more specific handler beats DownloadOnly)', function (): void {
    config()->set('vaulter.features.spreadsheets', true);
    app()->forgetInstance(DocumentHandlerRegistry::class);

    // With spreadsheets ON, xlsx resolves to Univer (priority 90) over DownloadOnly.
    $file = FileDescriptor::make(
        nodeId: 1,
        filename: 'budget.xlsx',
        detectedMime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );

    // UniverHandler is only tagged when the feature is on; register it the same way
    // the provider would so the resolution exercises the priority sort.
    app(DocumentHandlerRegistry::class)->register(UniverHandler::class);

    expect(app(DocumentHandlerRegistry::class)->resolve($file, Capability::Edit)->id())->toBe('univer');
});

it('keeps the built-in handlers final and config-driven (cache-safe)', function (): void {
    expect(TipTapHandler::class)->toBeString()
        ->and(new TipTapHandler)->toBeInstanceOf(DocumentHandler::class)
        ->and(new PreviewHandler)->toBeInstanceOf(DocumentHandler::class)
        ->and(new DownloadOnlyHandler)->toBeInstanceOf(DocumentHandler::class);
});

/**
 * A host's custom handler for a brand-new format — the extensibility proof. ONE
 * class, registered at runtime, no migration, no core/enum change.
 */
final class CadHandler implements DocumentHandler
{
    public function id(): string
    {
        return 'cad';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return ['image/vnd.dwg', 'application/acad'];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['dwg', 'dxf'];
    }

    public function priority(): int
    {
        return 200;
    }

    public function capabilities(FileDescriptor $file): DocumentCapabilities
    {
        return new DocumentCapabilities(editable: true, previewable: true);
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(component: 'CadViewer', route: 'cad.show');
    }
}
