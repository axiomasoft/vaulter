<?php

declare(strict_types=1);

/*
 * An exporter receives decoded inline content and nothing else: it must not be able
 * to read blobs, storage disks or URLs, so an export can never reach bytes the egress
 * gate did not release.
 */
arch('exporters never read storage, blobs or the HTTP layer')
    ->expect('Vaulter\Documents\Export')
    ->not->toUse([
        'Vaulter\Content',
        'Illuminate\Support\Facades\Storage',
        'Illuminate\Contracts\Filesystem',
        'Illuminate\Http',
        'Illuminate\Support\Facades\Http',
        'Vaulter\Documents\Http',
    ]);

arch('the removed export seams stay removed')
    ->expect([
        'Vaulter\Documents\Export\NodeExportService',
        'Vaulter\Documents\Enums\Export\ExportFormat',
        'Vaulter\Documents\Registries\DocumentExporterRegistry',
        'Vaulter\Contracts\Handlers\DocumentExporter',
        'Vaulter\Contracts\Handlers\DocumentContent',
    ])
    ->not->toBeUsed();

test('the exporter contract lives in documents only', function (): void {
    expect(interface_exists('Vaulter\Documents\Contracts\DocumentExporter'))->toBeTrue()
        ->and(interface_exists('Vaulter\Contracts\Handlers\DocumentExporter'))->toBeFalse()
        ->and(interface_exists('Vaulter\Contracts\Handlers\DocumentContent'))->toBeFalse()
        ->and(class_exists('Vaulter\Documents\Export\NodeExportService'))->toBeFalse();
});
