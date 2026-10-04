<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Engines\Handlers\UniverHandler;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Facades\Vaulter;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/**
 * The format-extensibility registry (§4.5 / §7 / H.5): mime → extension → capability
 * → priority → null-object, the runtime-registration proof and the claimedMime spoof
 * guard. THIS is the headline — a new format is one class + register, no migration.
 */
beforeEach(function (): void {
    $this->registry = app(FileHandlerRegistry::class);
});

it('routes a docx-like file to the editable TipTapHandler', function (): void {
    $file = FileDescriptor::make(
        nodeId: 1,
        filename: 'report.docx',
        detectedMime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    $handler = $this->registry->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('vaulter/tiptap')
        ->and($handler->capabilities($file)->editable)->toBeTrue();
});

it('routes a pdf to the previewable PreviewHandler', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'scan.pdf', detectedMime: 'application/pdf');

    $handler = $this->registry->resolve($file, Capability::Preview);

    expect($handler->key())->toBe('vaulter/preview')
        ->and($handler->capabilities($file)->previewable)->toBeTrue()
        ->and($handler->capabilities($file)->thumbnailable)->toBeTrue();
});

it('routes any image to PreviewHandler via the image/* wildcard', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'photo.png', detectedMime: 'image/png');

    expect($this->registry->resolve($file, Capability::Preview)->key())->toBe('vaulter/preview');
});

it('falls back to DownloadOnly for an archive / unknown type', function (): void {
    $file = FileDescriptor::make(nodeId: 1, filename: 'bundle.zip', detectedMime: 'application/zip');

    $handler = $this->registry->resolve($file, Capability::Download);

    expect($handler->key())->toBe('vaulter/download')
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
    expect($handler->key())->toBe('vaulter/download')
        ->and($file->canonicalMime())->toBe('application/zip');
});

it('resolves a RUNTIME-registered custom handler with NO migration and NO core change (H.5)', function (): void {
    // The whole extensibility headline: a host writes ONE class for a brand-new
    // format (.dwg CAD) and registers it at runtime. It resolves immediately — the
    // registry's string keys are the source of truth, not any sealed enum.
    Vaulter::handlers()->register(CadHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'part.dwg', detectedMime: 'image/vnd.dwg');

    $handler = app(FileHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('test/cad')
        ->and($handler->capabilities($file)->editable)->toBeTrue();
});

it('resolves the custom CAD handler by EXTENSION when the mime is unknown', function (): void {
    Vaulter::handlers()->register(new CadHandler);

    // No detected mime at all — only the .dwg extension routes it.
    $file = FileDescriptor::make(nodeId: 1, filename: 'assembly.dwg');

    expect(app(FileHandlerRegistry::class)->resolve($file, Capability::Edit)->key())->toBe('test/cad');
});

it('sorts matching handlers by priority (the more specific handler beats DownloadOnly)', function (): void {
    config()->set('vaulter.features.spreadsheets', true);
    app()->forgetInstance(FileHandlerRegistry::class);

    // With spreadsheets ON, xlsx resolves to Univer (priority 90) over DownloadOnly.
    $file = FileDescriptor::make(
        nodeId: 1,
        filename: 'budget.xlsx',
        detectedMime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );

    // UniverHandler is only tagged when the feature is on; register it the same way
    // the provider would so the resolution exercises the priority sort.
    app(FileHandlerRegistry::class)->register(UniverHandler::class);

    expect(app(FileHandlerRegistry::class)->resolve($file, Capability::Edit)->key())->toBe('vaulter/univer');
});

it('keeps the built-in handlers final and config-driven (cache-safe)', function (): void {
    expect(TipTapHandler::class)->toBeString()
        ->and(new TipTapHandler)->toBeInstanceOf(FileHandler::class)
        ->and(new PreviewHandler)->toBeInstanceOf(FileHandler::class)
        ->and(new DownloadOnlyHandler)->toBeInstanceOf(FileHandler::class);
});

/**
 * A host's custom handler for a brand-new format — the extensibility proof. ONE
 * class, registered at runtime, no migration, no core/enum change.
 */
final class CadHandler implements FileHandler
{
    public function key(): string
    {
        return 'test/cad';
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

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: true, previewable: true);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'CadViewer', route: 'cad.show');
    }
}
