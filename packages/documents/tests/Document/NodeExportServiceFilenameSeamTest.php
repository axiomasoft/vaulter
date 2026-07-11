<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Contracts\Export\ExportFilenameResolver;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Documents\NodeExportService;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;

/*
 * P4.5 / F-P3.1-04: the export filename scheme becomes a container-bound
 * extension point ({@see ExportFilenameResolver}) instead of a `private` method
 * on a class a host could not touch — `NodeExportService` stays `final` (the H.5
 * arch gate from P4.1 forbids un-finalising anything in
 * `Vaulter\Documents\Documents`), so the seam is DI-based, not inheritance-based.
 * Before the fix, there was no contract to rebind at all — a different naming
 * scheme required forking `NodeExportService::filenameFor()`.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    $this->node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $this->drive->storage(),
        name: 'Report',
        content: DocumentTestHelpers::content('Exportable body'),
        parent: $this->drive->root(),
    ));
});

it('binds ExportFilenameResolver to the default scheme unchanged, characterisation guard', function (): void {
    expect(app(ExportFilenameResolver::class))->toBeInstanceOf(DefaultExportFilenameResolver::class);

    $export = app(NodeExportService::class)->export($this->node, 'html');

    expect($export->filename)->toBe('Report.html');
});

it('lets a host rebind ExportFilenameResolver to override the export filename scheme, F-P3.1-04', function (): void {
    $custom = new class implements ExportFilenameResolver
    {
        public function resolve(Node $node, string $extension): string
        {
            return 'custom-'.$node->name.'.'.$extension;
        }
    };

    app()->bind(ExportFilenameResolver::class, fn (): ExportFilenameResolver => $custom);
    // NodeExportService itself is not rebound — a fresh resolve must pick up the
    // NEW filename resolver, proving the seam is read from the container, not
    // memoised/hardcoded inside the service.
    app()->forgetInstance(NodeExportService::class);

    $export = app(NodeExportService::class)->export($this->node, 'html');

    expect($export->filename)->toBe('custom-Report.html');
});

it('keeps NodeExportService final — the seam is DI-based, not inheritance-based (H.5)', function (): void {
    $class = new ReflectionClass(NodeExportService::class);

    expect($class->isFinal())->toBeTrue();
});
