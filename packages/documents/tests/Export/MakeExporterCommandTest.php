<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * vaulter:make:exporter scaffolds a host-owned exporter and leaves registration
 * (config) to the host.
 */
beforeEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

it('writes an exporter class with an app/ key and does not touch the config', function (): void {
    $before = config('vaulter-documents.exporters');

    $this->artisan('vaulter:make:exporter', ['name' => 'Pdf'])
        ->expectsOutputToContain('PdfExporter::class')
        ->assertSuccessful();

    $path = app_path('Vaulter/Exporters/PdfExporter.php');
    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))
        ->toContain('namespace App\\Vaulter\\Exporters;')
        ->toContain('final class PdfExporter implements DocumentExporter')
        ->toContain("return 'app/pdf';")
        ->toContain("return 'pdf';")
        ->and(config('vaulter-documents.exporters'))->toBe($before);
});

it('refuses to overwrite without --force and rejects an invalid name', function (): void {
    $this->artisan('vaulter:make:exporter', ['name' => 'pdf-export'])->assertSuccessful();
    $this->artisan('vaulter:make:exporter', ['name' => 'PdfExport'])->assertFailed();
    $this->artisan('vaulter:make:exporter', ['name' => 'PdfExport', '--force' => true])->assertSuccessful();
    $this->artisan('vaulter:make:exporter', ['name' => '!!'])->assertFailed();
});
