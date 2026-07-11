<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Contracts\Documents\HandlerRegistry;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\Documents\FakeExporter;
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
 * F-P3.3-02 / F-P3.5-01 — cross-package seams (HandlerRegistry from
 * vaulter-documents, DriveHandleFactory from vaulter-drive) now fail loud with an
 * install hint (a MissingPackageException naming the composer package), instead of
 * the generic Illuminate BindingResolutionException ("Target [...] is not
 * instantiable.") that carried no mention of "vaulter" or which package to
 * install. Red-before-fix (D7): before this item, catching
 * MissingPackageException below would not catch anything (the container's own
 * exception class propagated instead).
 *
 * The CORE test suite (this file) does NOT load VaulterDocumentsServiceProvider
 * (see tests/Pest.php / TestCase::getPackageProviders) — HandlerRegistry is
 * genuinely unbound here, exercising the real "documents package absent" path,
 * not a mock.
 */
it('handlers() fails loud with an install hint when vaulter-documents is absent, F-P3.3-02/F-P3.5-01', function (): void {
    expect(app()->bound(HandlerRegistry::class))->toBeFalse();

    expect(fn () => app(VaulterManager::class)->handlers())
        ->toThrow(MissingPackageException::class);

    try {
        app(VaulterManager::class)->handlers();
    } catch (MissingPackageException $e) {
        expect($e->getMessage())
            ->toContain('HandlerRegistry')
            ->toContain('axioma-studio/vaulter-documents')
            ->toContain('Fix: composer require axioma-studio/vaulter-documents');
    }
});

it('registerHandler() fails loud with the same install hint, F-P3.3-02/F-P3.5-01', function (): void {
    expect(fn () => app(VaulterManager::class)->registerHandler('Some\\Handler'))
        ->toThrow(MissingPackageException::class, 'axioma-studio/vaulter-documents');
});

/*
 * A pure unit variant (no Laravel app at all) — proves the install-hint mechanism
 * on VaulterManager::for() (the DriveHandleFactory seam, F-P3.5-01) with a bare
 * container that has NOTHING bound, isolating it from whatever the core test
 * suite happens to wire (VaulterDriveServiceProvider IS loaded here).
 */
it('for() fails loud with an install hint when vaulter-drive is absent from a bare container, F-P3.5-01', function (): void {
    $manager = new VaulterManager(new Container);

    expect(fn () => $manager->for(new Node))
        ->toThrow(MissingPackageException::class, 'axioma-studio/vaulter-drive');
});

it('handlers() fails loud with an install hint from a bare container too, F-P3.3-02', function (): void {
    $manager = new VaulterManager(new Container);

    expect(fn () => $manager->handlers())
        ->toThrow(MissingPackageException::class, 'axioma-studio/vaulter-documents');
});
