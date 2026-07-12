<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Contracts\Handlers\HandlerRegistry;
use Vaulter\Engines\Handlers\DownloadOnlyHandler;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\Documents\FakeExporter;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\HandlerCapabilities;
use Vaulter\ValueObjects\Handlers\HandlerUi;
use Vaulter\VaulterManager;

/*
 * F-P3.3-01 — VaulterManager::registerExporter() now accepts an FQCN, symmetric to
 * registerHandler(). Red-before-fix (D7): the prior signature was
 * `registerExporter(DocumentExporter $exporter)` — passing a class-string was a
 * strict_types=1 TypeError, not a resolved instance.
 */
it('registerExporter accepts a class-string exporter and resolves it through the container, F-P3.3-01', function (): void {
    $manager = app(VaulterManager::class);

    $manager->registerExporter(FakeExporter::class);

    expect($manager->exporterFormats())->toContain('fake')
        ->and($manager->exporter('fake'))->toBeInstanceOf(FakeExporter::class);
});

it('registerExporter still accepts a ready instance (unchanged path), F-P3.3-01', function (): void {
    $manager = app(VaulterManager::class);
    $exporter = new FakeExporter;

    $manager->registerExporter($exporter);

    expect($manager->exporter('fake'))->toBe($exporter);
});

/*
 * F-P3.3-02 / F-P3.5-01 — the DriveHandleFactory seam (vaulter-drive) fails loud
 * with an install hint (a MissingPackageException naming the composer package),
 * instead of the generic Illuminate BindingResolutionException ("Target [...] is
 * not instantiable.") that carried no mention of "vaulter" or which package to
 * install.
 *
 * The HandlerRegistry seam is DIFFERENT since VLT-CANON P0.4 (D10, superseding
 * H.5/T5): the engine is core-owned, not a vaulter-documents seam, so it no longer
 * "fails loud when absent" — see the tests below, which pin the OPPOSITE: it
 * always resolves.
 */
it('for() fails loud with an install hint when vaulter-drive is absent from a bare container, F-P3.5-01', function (): void {
    $manager = new VaulterManager(new Container);

    expect(fn () => $manager->for(new Node))
        ->toThrow(MissingPackageException::class, 'axioma-studio/vaulter-drive');
});

/*
 * P0.4/D10 — the format-extensibility registry (HandlerRegistry + its
 * PreviewHandler/DownloadOnlyHandler baseline) is core-owned, superseding H.5's
 * "concrete engine ships in vaulter-documents" allocation: a drive-only host with
 * NO vaulter-documents installed still resolves a real preview/download handler,
 * never a MissingPackageException. The CORE test suite (this file) does NOT load
 * VaulterDocumentsServiceProvider (tests/Pest.php / TestCase::getPackageProviders),
 * so this exercises the real "documents package absent" path, not a mock.
 */
it('handlers() resolves the core-owned registry even when vaulter-documents is absent, P0.4/D10', function (): void {
    expect(app()->bound(HandlerRegistry::class))->toBeTrue();

    $registry = app(VaulterManager::class)->handlers();

    expect($registry)->toBeInstanceOf(HandlerRegistry::class);

    $handler = $registry->resolve(FileDescriptor::make(nodeId: 1, filename: 'mystery', detectedMime: null));

    expect($handler)->toBeInstanceOf(DownloadOnlyHandler::class);
});

it('registerHandler() registers a custom handler with NO package installed, P0.4/D10', function (): void {
    $manager = app(VaulterManager::class);

    $manager->registerHandler(SeamTestCadHandler::class);

    $handler = $manager->handlers()->resolve(
        FileDescriptor::make(nodeId: 1, filename: 'part.dwg', detectedMime: 'image/vnd.dwg'),
    );

    expect($handler->id())->toBe('seam-cad');
});

/**
 * A minimal FileHandler fixture — proves the runtime-registration seam works
 * standalone, with no vaulter-documents package installed at all (P0.4/D10).
 */
final class SeamTestCadHandler implements FileHandler
{
    public function id(): string
    {
        return 'seam-cad';
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

    public function capabilities(FileDescriptor $file): HandlerCapabilities
    {
        return new HandlerCapabilities(editable: true);
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(component: 'SeamCadViewer', route: null);
    }
}
