<?php

declare(strict_types=1);

use App\Vaulter\Profiles\ProjectDriveProfile;
use Illuminate\Support\Facades\File;
use Vaulter\Profiles\ProfileRegistry;

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
        ->toContain("->key('project-drive')")
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
        ->toContain('->owners(\\App\\Models\\Project::class)')
        ->toContain('->grants(false)');
});

it('не перезаписывает существующий провайдер без --force', function (): void {
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])->assertSuccessful();
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])->assertFailed();
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive', '--force' => true])->assertSuccessful();
});

it('пишет Pest-тест, который лентится и проходит на сгенерированном профиле', function (): void {
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
    $this->artisan('vaulter:make:profile', ['name' => 'project_drive'])->assertSuccessful();

    $test = base_path('tests/Unit/Vaulter/ProjectDriveProfileTest.php');
    expect($test)->toBeFile();
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($test).' 2>&1', $output, $code);
    expect($code)->toBe(0)
        ->and(File::get($test))->toContain('ProfileRegistry')->toContain("has('project-drive')");

    require_once $this->panelDir.'/ProjectDriveProfile.php';
    $registry = new ProfileRegistry;
    $registry->register(new ProjectDriveProfile);
    $registry->freeze();
    expect($registry->has('project-drive'))->toBeTrue()
        ->and($registry->get('project-drive')->getDrivePolicy())->not->toBeNull();

    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});
