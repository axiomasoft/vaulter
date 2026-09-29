<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelMigrationGenerator;
use Vaulter\Panels\PanelRegistry;

beforeEach(function (): void {
    $this->generator = new PanelMigrationGenerator(new Filesystem);
    $this->outDir = sys_get_temp_dir().'/vaulter_panel_migrations_'.uniqid();
    config()->set('vaulter.migrations.path', $this->outDir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->outDir);
});

it('отвергает панель без собственных таблиц', function (): void {
    $panel = (new Panel)->id('shared');

    expect(fn () => $this->generator->generate(panel: $panel))
        ->toThrow(
            InvalidArgumentException::class,
            'Vaulter panel [shared] writes to the shared default-panel schema and needs no generated migration. '.
            'Give it a tablePrefix() or a tables() map to generate per-panel tables.',
        );
});

it('генерирует per-panel миграцию с именем по префиксу', function (): void {
    $panel = (new Panel)->id('project')->tablePrefix('project_drive');

    $file = $this->generator->generate(panel: $panel);

    expect(basename($file))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_project_drive_drive_tables\.php$/')
        ->and(file_get_contents($file))
        ->toContain('for the "project" panel (project_drive_*).')
        ->toContain("->get('project')")
        ->not->toContain('{{ panel }}', '{{ prefix }}');
});

it('отдаёт приоритет явному префиксу над табличной картой', function (): void {
    $panel = (new Panel)
        ->id('project')
        ->tablePrefix('project_drive')
        ->tables(['storages' => 'mapped_storages']);

    $file = $this->generator->generate(panel: $panel);

    expect(basename($file))->toEndWith('_create_project_drive_drive_tables.php')
        ->and(file_get_contents($file))->toContain('for the "project" panel (project_drive_*).');
});

it('migrationExists игнорирует timestamp и блокирует без force', function (): void {
    $panel = (new Panel)->id('project')->tablePrefix('project_drive');

    $this->generator->generate(panel: $panel);

    expect(fn () => $this->generator->generate(panel: $panel))
        ->toThrow(InvalidArgumentException::class, 'already exists');

    // With force it overwrites silently.
    expect($this->generator->generate(panel: $panel, force: true))->toBeString();
});

it('сгенерированная миграция создаёт префиксные таблицы с FK на свои таблицы', function (): void {
    $panel = (new Panel)->id('proj')->tablePrefix('proj_drive');
    app(PanelRegistry::class)->register($panel);

    $file = $this->generator->generate(panel: $panel);

    // Run the generated migration as the host would.
    $migration = require $file;
    $migration->up();

    expect(Schema::hasTable('proj_drive_storages'))->toBeTrue()
        ->and(Schema::hasTable('proj_drive_nodes'))->toBeTrue()
        ->and(Schema::hasTable('proj_drive_blobs'))->toBeTrue()
        ->and(Schema::hasTable('proj_drive_files'))->toBeTrue()
        ->and(Schema::hasTable('proj_drive_file_versions'))->toBeTrue()
        ->and(Schema::hasTable('proj_drive_file_version_blobs'))->toBeTrue();

    // FKs point at the panel's OWN tables, not the shared v_* schema.
    $foreignTables = collect(Schema::getForeignKeys('proj_drive_nodes'))
        ->pluck('foreign_table')
        ->all();

    expect($foreignTables)->toContain('proj_drive_storages')
        ->and($foreignTables)->toContain('proj_drive_nodes')
        ->and($foreignTables)->not->toContain('v_storages');

    $migration->down();

    expect(Schema::hasTable('proj_drive_nodes'))->toBeFalse();
});
