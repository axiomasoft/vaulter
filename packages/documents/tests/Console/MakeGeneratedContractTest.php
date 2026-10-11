<?php

declare(strict_types=1);

use App\Vaulter\DocumentTypes\KanbanDocumentType;
use App\Vaulter\Exporters\PdfExporter;
use Illuminate\Support\Facades\File;
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;
use Vaulter\Documents\Testing\Contracts\ExporterContractTests;

uses(DocumentTypeContractTests::class);

beforeEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

it('scaffolds a document type and a test that lint, and the type passes its contract', function (): void {
    $this->artisan('vaulter:make:document-type', ['name' => 'Kanban'])->assertSuccessful();

    $class = app_path('Vaulter/DocumentTypes/KanbanDocumentType.php');
    $test = base_path('tests/Unit/Vaulter/KanbanDocumentTypeTest.php');
    expect($test)->toBeFile();
    foreach ([$class, $test] as $path) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
        expect($code)->toBe(0);
    }
    expect(File::get($test))->toContain('assertDocumentTypeContract');

    require_once $class;
    $this->assertDocumentTypeContract(new KanbanDocumentType);
});

it('scaffolds an exporter and a test that lint, and the exporter passes its contract', function (): void {
    $this->artisan('vaulter:make:exporter', ['name' => 'Pdf'])->assertSuccessful();

    $class = app_path('Vaulter/Exporters/PdfExporter.php');
    $test = base_path('tests/Unit/Vaulter/PdfExporterTest.php');
    expect($test)->toBeFile();
    foreach ([$class, $test] as $path) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
        expect($code)->toBe(0);
    }
    expect(File::get($test))->toContain('assertExporterContract');

    require_once $class;
    (new ExporterContractTests)->assertExporterContract(new PdfExporter);
});

it('keeps an existing test unless --force is passed', function (): void {
    $this->artisan('vaulter:make:exporter', ['name' => 'Pdf'])->assertSuccessful();
    $test = base_path('tests/Unit/Vaulter/PdfExporterTest.php');
    File::put($test, 'mine');

    $this->artisan('vaulter:make:exporter', ['name' => 'Pdf', '--force' => true])->assertSuccessful();
    expect(File::get($test))->toContain('assertExporterContract');
});
