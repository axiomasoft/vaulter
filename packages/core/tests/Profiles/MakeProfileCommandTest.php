<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->panelDir = app_path('Vaulter/Profiles');
    File::deleteDirectory(app_path('Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

it('создаёт файл провайдера панели и НЕ трогает конфиг и миграции', function (): void {
    $migrationsBefore = File::exists(database_path('migrations'))
        ? File::files(database_path('migrations'))
        : [];

    $configBefore = config('vaulter.profiles.providers');

    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])
        ->assertSuccessful();

    $path = $this->panelDir.'/ProjectDriveProfile.php';

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\\Vaulter\\Profiles;')
        ->toContain('final class ProjectDriveProfile extends ProfileProvider')
        ->toContain("->id('project_drive')")
        ->toContain('')
        // Profiles share the schema and never emit own-table settings.
        ->not->toContain('->tablePrefix(');

    // Config untouched and no migrations written.
    expect(config('vaulter.profiles.providers'))->toBe($configBefore);

    $migrationsAfter = File::exists(database_path('migrations'))
        ? File::files(database_path('migrations'))
        : [];

    expect($migrationsAfter)->toBe($migrationsBefore);
});

it('эмитит host и withoutGrants по флагам', function (): void {
    $this->artisan('vaulter:make:profile', [
        'name' => 'project_drive',
        '--host' => 'App\\Models\\Project',
        '--no-grants' => true,
    ])->assertSuccessful();

    $contents = File::get($this->panelDir.'/ProjectDriveProfile.php');

    expect($contents)
        ->toContain('->host(\\App\\Models\\Project::class)')
        ->toContain('->withoutGrants()');
});

it('не перезаписывает существующий провайдер без --force', function (): void {
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])->assertSuccessful();
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])->assertFailed();
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive', '--force' => true])->assertSuccessful();
});
