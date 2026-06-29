<?php

declare(strict_types=1);

namespace Vaulter\Panels;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates the real, timestamped migration that first installs a panel's
 * per-panel tables (the rare advanced own-tables mode, D12). It reimplements the
 * MigrationCreator contract rather than extending GeneratorCommand (which cannot
 * emit a timestamped name): stub -> placeholder substitution -> timestamped
 * filename -> write via Filesystem.
 *
 * The generated file is intentionally thin and git-visible: it resolves the panel
 * from the registry at migration time and delegates the column definitions to
 * PanelTablesBuilder, so it can never drift from the shared schema. Schema
 * EVOLUTION is a separate concern handled by versioned delta migrations, not by
 * regenerating this file.
 */
final readonly class PanelMigrationGenerator
{
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * Generate the per-panel migration file and return its absolute path. A
     * prefix-less default-style panel is rejected: the default panel ships with
     * the base migrations and needs no generated file. When several files are
     * generated in one run, a single base timestamp plus a per-file offset keeps
     * names collision-free.
     *
     * @throws InvalidArgumentException when the panel has no per-panel tables, or
     *                                  when a migration for it already exists and
     *                                  $force is false.
     */
    public function generate(Panel $panel, bool $force = false, int $offset = 0): string
    {
        $prefix = $this->prefixFor(panel: $panel);

        $name = "create_{$prefix}_drive_tables";
        $path = $this->path();

        if (!$force && $this->migrationExists(name: $name, path: $path)) {
            throw new InvalidArgumentException(
                "A migration [{$name}] for Vaulter panel [{$panel->getId()}] already exists. Pass force to overwrite.",
            );
        }

        $this->files->ensureDirectoryExists($path);

        $file = $path.DIRECTORY_SEPARATOR.$this->timestamp(offset: $offset)."_{$name}.php";

        $this->files->put($file, $this->render(panel: $panel, prefix: $prefix));

        return $file;
    }

    /**
     * The filename prefix for the panel's tables. An explicit prefix wins; else a
     * table map's storages entry is reduced to its "{prefix}_storages" stem. A
     * panel writing to the shared schema (no own tables) is rejected — it needs no
     * generated migration.
     */
    private function prefixFor(Panel $panel): string
    {
        if (!$panel->hasOwnTables()) {
            throw new InvalidArgumentException(
                "Vaulter panel [{$panel->getId()}] writes to the shared default-panel schema and needs no generated migration. ".
                'Give it a tablePrefix() or a tables() map to generate per-panel tables.',
            );
        }

        $prefix = $panel->tablePrefixValue()
            ?? Str::beforeLast($panel->table('storages'), '_storages');

        return Str::snake($prefix);
    }

    private function render(Panel $panel, string $prefix): string
    {
        $stub = $this->files->get($this->stubPath());

        return str_replace(
            ['{{ panel }}', '{{ prefix }}'],
            [$panel->getId(), $prefix],
            $stub,
        );
    }

    /**
     * Whether a migration with this name already exists in the output path,
     * matching MigrationCreator's name-collision check (ignoring the timestamp).
     */
    private function migrationExists(string $name, string $path): bool
    {
        /** @var list<string> $existing */
        $existing = $this->files->glob($path.DIRECTORY_SEPARATOR.'*.php');

        foreach ($existing as $file) {
            if (str_ends_with(basename($file, '.php'), "_{$name}")) {
                return true;
            }
        }

        return false;
    }

    private function timestamp(int $offset): string
    {
        return Carbon::now()->addSeconds($offset)->format('Y_m_d_His');
    }

    private function path(): string
    {
        $path = config('vaulter.migrations.path');

        return is_string($path) && $path !== '' ? $path : database_path('migrations');
    }

    private function stubPath(): string
    {
        return __DIR__.'/../../stubs/panel/panel-migration.stub';
    }
}
