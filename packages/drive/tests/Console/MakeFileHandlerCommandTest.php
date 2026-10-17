<?php

declare(strict_types=1);

use App\Vaulter\FileHandlers\Csv;
use Illuminate\Support\Facades\File;
use Vaulter\Testing\Contracts\FileHandlerContractTests;

beforeEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

it('writes a handler and a contract test that both lint, and the handler passes its contract', function (): void {
    $handlersBefore = config('vaulter.handlers');

    $this->artisan('vaulter:make:file-handler', ['name' => 'Csv'])
        ->expectsOutputToContain('Csv::class')
        ->assertSuccessful();

    $class = app_path('Vaulter/FileHandlers/Csv.php');
    $test = base_path('tests/Unit/Vaulter/CsvTest.php');
    expect($class)->toBeFile()->and($test)->toBeFile();
    foreach ([$class, $test] as $path) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
        expect($code)->toBe(0);
    }
    expect(File::get($test))->toContain('FileHandlerContractTests')
        ->and(config('vaulter.handlers'))->toBe($handlersBefore);

    require_once $class;
    $handler = new Csv;
    (new FileHandlerContractTests)->assertFileHandlerContract($handler);
    expect($handler->key())->toBe('app/csv');
});

it('refuses to overwrite without --force and overwrites with it', function (): void {
    $this->artisan('vaulter:make:file-handler', ['name' => 'Csv'])->assertSuccessful();
    $this->artisan('vaulter:make:file-handler', ['name' => 'Csv'])->assertFailed();

    File::put(base_path('tests/Unit/Vaulter/CsvTest.php'), 'changed');
    $this->artisan('vaulter:make:file-handler', ['name' => 'Csv', '--force' => true])->assertSuccessful();
    expect(File::get(base_path('tests/Unit/Vaulter/CsvTest.php')))->toContain('FileHandlerContractTests');
});

it('rejects an invalid name', function (): void {
    $this->artisan('vaulter:make:file-handler', ['name' => '!!'])->assertFailed();
});
