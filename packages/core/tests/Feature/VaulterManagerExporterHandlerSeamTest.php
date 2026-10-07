<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Registries\DocumentExporterRegistry;
use Vaulter\Documents\Registries\DocumentTypeRegistry;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\Documents\FakeExporter;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;
use Vaulter\VaulterManager;

/*
 * F-P3.3-01 — VaulterManager::registerExporter() now accepts an FQCN, symmetric to
 * registerHandler(). Red-before-fix (D7): the prior signature was
 * `registerExporter(DocumentExporter $exporter)` — passing a class-string was a
 * strict_types=1 TypeError, not a resolved instance.
 */
it('registerExporter accepts a class-string exporter and resolves it through the container, F-P3.3-01', function (): void {
    $manager = new DocumentsManager(new DocumentTypeRegistry, new DocumentExporterRegistry(app(), app(DocumentsConfig::class)));

    $manager->exporters()->register(FakeExporter::class);

    expect($manager->exporters()->formats())->toContain('fake')
        ->and($manager->exporters()->get('fake'))->toBeInstanceOf(FakeExporter::class);
});

it('registerExporter still accepts a ready instance (unchanged path), F-P3.3-01', function (): void {
    $manager = new DocumentsManager(new DocumentTypeRegistry, new DocumentExporterRegistry(app(), app(DocumentsConfig::class)));
    $exporter = new FakeExporter;

    $manager->exporters()->register($exporter);

    expect($manager->exporters()->get('fake'))->toBe($exporter);
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
it('drive() fails loud with an install hint when vaulter-drive is absent from a bare container, F-P3.5-01', function (): void {
    $manager = new VaulterManager(new Container);

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
