<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

/*
 * The export filename scheme is a container-bound extension point
 * ({@see ExportFilenameResolver}): ExportDocumentAction stays final, so a host
 * rebinds the contract instead of forking the action.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    $this->node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->drive->model()), new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Report',
        content: DocumentTestHelpers::content('Exportable body'),
        parent: DriveTestHelpers::model($this->drive->root()),
    ));
});

it('binds ExportFilenameResolver to the default scheme', function (): void {
    expect(app(ExportFilenameResolver::class))->toBeInstanceOf(DefaultExportFilenameResolver::class);

    $export = app(ExportDocumentAction::class)->execute(DriveContext::system($this->node->storage()->firstOrFail(), 'export fixture'), new ExportDocumentInput($this->node, 'html'));

    expect($export->filename)->toBe('Report.html');
});

it('lets a host rebind ExportFilenameResolver to override the export filename scheme', function (): void {
    $custom = new class implements ExportFilenameResolver
    {
        public function resolve(Node $node, string $extension): string
        {
            return 'custom-'.$node->name.'.'.$extension;
        }
    };

    app()->bind(ExportFilenameResolver::class, fn (): ExportFilenameResolver => $custom);
    // The action itself is not rebound — a fresh resolve must pick up the new
    // resolver, proving the seam is read from the container, not hardcoded.
    app()->forgetInstance(ExportDocumentAction::class);

    $export = app(ExportDocumentAction::class)->execute(DriveContext::system($this->node->storage()->firstOrFail(), 'export fixture'), new ExportDocumentInput($this->node, 'html'));

    expect($export->filename)->toBe('custom-Report.html');
});

it('keeps ExportDocumentAction final — the seam is DI-based, not inheritance-based', function (): void {
    $class = new ReflectionClass(ExportDocumentAction::class);

    expect($class->isFinal())->toBeTrue();
});
