<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->panelDir = app_path('Vaulter/Panels');
    File::deleteDirectory(app_path('Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

it('создаёт файл провайдера панели и НЕ трогает конфиг и миграции', function (): void {
    $migrationsBefore = File::exists(database_path('migrations'))
        ? File::files(database_path('migrations'))
        : [];

    $configBefore = config('vaulter.panels');

    $this->artisan('vaulter:make-panel', ['name' => 'project_drive'])
        ->assertSuccessful();

    $path = "{$this->panelDir}/ProjectDriveStoragePanel.php";

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\\Vaulter\\Panels;')
        ->toContain('final class ProjectDriveStoragePanel extends StoragePanelProvider')
        ->toContain("->id('project_drive')")
        ->toContain("->storageType('private')")
        // zero-migration default: no prefix emitted without --prefix.
        ->not->toContain('->tablePrefix(');

    // Config untouched and no migrations written.
    expect(config('vaulter.panels'))->toBe($configBefore);

    $migrationsAfter = File::exists(database_path('migrations'))
        ? File::files(database_path('migrations'))
        : [];

    expect($migrationsAfter)->toBe($migrationsBefore);
});

it('эмитит host, prefix, id strategy и withoutGrants по флагам', function (): void {
    $this->artisan('vaulter:make-panel', [
        'name' => 'project_drive',
        '--host' => 'App\\Models\\Project',
        '--prefix' => 'proj_drive',
        '--id' => 'uuid7',
        '--no-grants' => true,
    ])->assertSuccessful();

    $contents = File::get("{$this->panelDir}/ProjectDriveStoragePanel.php");

    expect($contents)
        ->toContain('->host(\\App\\Models\\Project::class)')
        ->toContain("->tablePrefix('proj_drive')")
        ->toContain("->idStrategy('uuid7')")
        ->toContain('->withoutGrants()');
});

it('не перезаписывает существующий провайдер без --force', function (): void {
    $this->artisan('vaulter:make-panel', ['name' => 'project_drive'])->assertSuccessful();
    $this->artisan('vaulter:make-panel', ['name' => 'project_drive'])->assertFailed();
    $this->artisan('vaulter:make-panel', ['name' => 'project_drive', '--force' => true])->assertSuccessful();
});
