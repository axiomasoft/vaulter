<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Tests\Fixtures\FakeExporter;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;
use Vaulter\VaulterManager;

/*
 * The exporter registry takes a class name (resolved through the container) or a
 * ready instance, like the handler and type registries.
 */
it('the exporter registry accepts a class-string exporter and resolves it through the container', function (): void {
    $manager = new DocumentsManager(new DocumentTypeRegistry(app()), new DocumentExporterRegistry(app()));

    $manager->exporters()->register(FakeExporter::class);

    expect($manager->exporters()->formatsFor('vaulter/rich-text'))->toBe(['fake'])
        ->and($manager->exporters()->for('vaulter/rich-text', 'fake'))->toBeInstanceOf(FakeExporter::class);
});

it('the exporter registry still accepts a ready instance', function (): void {
    $manager = new DocumentsManager(new DocumentTypeRegistry(app()), new DocumentExporterRegistry(app()));
    $exporter = new FakeExporter;

    $manager->exporters()->register($exporter);

    expect($manager->exporters()->for('vaulter/rich-text', 'fake'))->toBe($exporter);
});

/*
 * F-P3.3-02 / F-P3.5-01 — the DriveHandleFactory seam (vaulter-drive) fails loud
 * with an install hint (a MissingPackageException naming the composer package),
 * instead of the generic Illuminate BindingResolutionException ("Target [...] is
 * not instantiable.") that carried no mention of "vaulter" or which package to
 * install.
 *
 * The FileHandlerRegistry seam is DIFFERENT since VLT-CANON P0.4 (D10, superseding
 * H.5/T5): the engine is core-owned, not a vaulter-documents seam, so it no longer
 * "fails loud when absent" — see the tests below, which pin the OPPOSITE: it
 * always resolves.
 */
it('drive() fails loud with an install hint when no active module provides the drive factory, F-P3.5-01', function (): void {
    $manager = new VaulterManager(FreshApplication::boot(['vaulter.modules' => ['drive' => false]]));

    expect(fn () => $manager->drive(new Node, 'default'))
        ->toThrow(MissingPackageException::class, 'axioma-studio/vaulter-drive');
});

/*
 * P0.4/D10 — the format-extensibility registry (FileHandlerRegistry + its
 * PreviewHandler/DownloadOnlyHandler baseline) is core-owned, superseding H.5's
 * "concrete engine ships in vaulter-documents" allocation: a drive-only host with
 * NO vaulter-documents installed still resolves a real preview/download handler,
 * never a MissingPackageException. The CORE test suite (this file) does NOT load
 * VaulterDocumentsServiceProvider (tests/Pest.php / TestCase::getPackageProviders),
 * so this exercises the real "documents package absent" path, not a mock.
 */
it('handlers() resolves the core-owned registry even when vaulter-documents is absent, P0.4/D10', function (): void {
    expect(app()->bound(FileHandlerRegistry::class))->toBeTrue();

    $registry = app(VaulterManager::class)->handlers();

    expect($registry)->toBeInstanceOf(FileHandlerRegistry::class);

    $handler = $registry->resolve(FileDescriptor::make(nodeId: 1, filename: 'mystery', detectedMime: null));

    expect($handler)->toBeInstanceOf(DownloadOnlyHandler::class);
});

it('registerHandler() registers a custom handler with NO package installed, P0.4/D10', function (): void {
    $manager = app(VaulterManager::class);

    $manager->handlers()->register(SeamTestCadHandler::class);

    $handler = $manager->handlers()->resolve(
        FileDescriptor::make(nodeId: 1, filename: 'part.dwg', detectedMime: 'image/vnd.dwg'),
    );

    expect($handler->key())->toBe('test/seam-cad');
});

/**
 * A minimal FileHandler fixture — proves the runtime-registration seam works
 * standalone, with no vaulter-documents package installed at all (P0.4/D10).
 */
final class SeamTestCadHandler implements FileHandler
{
    public function key(): string
    {
        return 'test/seam-cad';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return ['image/vnd.dwg'];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['dwg'];
    }

    public function priority(): int
    {
        return 200;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: true);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'SeamCadViewer', route: null);
    }
}
