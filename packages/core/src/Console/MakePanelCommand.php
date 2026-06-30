<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Scaffolds a storage panel (D11): a consumer-owned StoragePanelProvider class
 * in app/Vaulter/Panels, from a stub, so the consumer immediately has a thin
 * class to edit (feature flags, fill/present hooks, pipes).
 *
 * It does NOT touch the published config and does NOT generate migrations: a
 * panel is a zero-migration config-envelope over the shared v_* schema by
 * default. Registering the provider FQCN in config('vaulter.panels'), and (only
 * for the rare own-tables mode) generating migrations, are deliberate next steps
 * printed at the end.
 */
final class MakePanelCommand extends Command
{
    protected $signature = 'vaulter:make-panel
        {name : The panel id (snake/kebab), e.g. project_drive}
        {--host= : The host model FQCN this storage embeds into}
        {--prefix= : Table name prefix — opts into the advanced own-tables mode}
        {--no-grants : Generate a panel without per-node grants}
        {--id= : Primary key strategy for this panel (ulid|uuid7|bigint) — own-tables only}
        {--force : Overwrite the provider if it already exists}';

    protected $description = 'Scaffold a Vaulter storage panel provider (zero-migration config envelope)';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $id = Str::snake(Str::lower($rawName));
        $studly = Str::studly($id);

        $provider = $this->writeProvider(
            studly: $studly,
            id: $id,
            force: (bool) $this->option('force'),
        );

        if ($provider === null) {
            return self::FAILURE;
        }

        $this->printNextSteps(studly: $studly, id: $id);

        return self::SUCCESS;
    }

    private function writeProvider(string $studly, string $id, bool $force): ?string
    {
        $namespace = 'App\\Vaulter\\Panels';
        $class = "{$studly}StoragePanel";
        $directory = app_path('Vaulter/Panels');
        $this->files->ensureDirectoryExists($directory);

        $path = "{$directory}/{$class}.php";

        if (!$force && $this->files->exists($path)) {
            $this->components->error("Panel provider [{$namespace}\\{$class}] already exists. Pass --force to overwrite.");

            return null;
        }

        $this->files->put($path, $this->renderProvider(
            namespace: $namespace,
            class: $class,
            id: $id,
        ));

        $this->components->task("Panel provider app/Vaulter/Panels/{$class}.php");

        return $path;
    }

    private function renderProvider(string $namespace, string $class, string $id): string
    {
        $stub = $this->files->get($this->stubPath('storage-panel-provider'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ id }}', '{{ storage_type }}', '{{ host }}', '{{ prefix }}', '{{ id_strategy }}', '{{ grants }}'],
            [$namespace, $class, $id, $this->resolveStorageType(), $this->renderHost(), $this->renderPrefix(id: $id), $this->renderIdStrategy(), $this->renderGrants()],
            $stub,
        );
    }

    private function renderHost(): string
    {
        $host = $this->option('host');

        if (!is_string($host) || $host === '') {
            return '';
        }

        return PHP_EOL.'            ->host(\\'.ltrim($host, '\\').'::class)';
    }

    /**
     * A table prefix opts the panel into the advanced own-tables mode (D12); when
     * it is set we also emit the id-strategy override, which is only coherent with
     * own tables.
     */
    private function renderPrefix(string $id): string
    {
        $prefix = $this->option('prefix');

        if (!is_string($prefix) || $prefix === '') {
            return '';
        }

        return PHP_EOL."            ->tablePrefix('".Str::snake($prefix)."')";
    }

    private function renderIdStrategy(): string
    {
        $prefix = $this->option('prefix');
        $strategy = $this->option('id');

        // A per-panel id strategy is only coherent in own-tables mode, so it is
        // emitted alongside a prefix; otherwise the panel keeps the global default.
        if (!is_string($prefix) || $prefix === '' || !is_string($strategy) || $strategy === '') {
            return '';
        }

        return PHP_EOL."            ->idStrategy('{$strategy}')";
    }

    private function renderGrants(): string
    {
        return (bool) $this->option('no-grants')
            ? PHP_EOL.'            ->withoutGrants()'
            : '';
    }

    private function resolveStorageType(): string
    {
        return 'private';
    }

    private function printNextSteps(string $studly, string $id): void
    {
        $providerFqcn = "App\\Vaulter\\Panels\\{$studly}StoragePanel";

        $this->newLine();
        $this->components->info("Storage panel [{$id}] scaffolded.");
        $this->line('Next steps:');
        $this->line("  1. Register the provider in <info>config/vaulter.php</info> under 'panels':");
        $this->line("         \\{$providerFqcn}::class,");
        $this->line('  2. Edit the provider: feature flags, fill/present hooks, pipes.');
        $this->line('  3. ONLY if you set a tablePrefix: run <info>php artisan vaulter:panel:migrations '.$id.'</info>');
        $this->line('  4. Add any custom columns in your own follow-up migration.');
    }

    private function stubPath(string $name): string
    {
        return __DIR__."/../../stubs/panel/{$name}.stub";
    }
}
