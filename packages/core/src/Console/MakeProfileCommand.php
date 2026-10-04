<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Scaffolds a consumer-owned ProfileProvider over the shared Vaulter schema. */
final class MakeProfileCommand extends Command
{
    protected $signature = 'vaulter:make:profile
        {name : The profile id (snake/kebab), e.g. project_drive}
        {--host= : The host model FQCN this storage embeds into}
        {--no-grants : Generate a profile without per-node grants}
        {--force : Overwrite the provider if it already exists}';

    protected $description = 'Scaffold a Vaulter profile provider (zero-migration config envelope)';

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
        $namespace = 'App\\Vaulter\\Profiles';
        $class = "{$studly}Profile";
        $directory = app_path('Vaulter/Profiles');
        $this->files->ensureDirectoryExists($directory);

        $path = "{$directory}/{$class}.php";

        if (!$force && $this->files->exists($path)) {
            $this->components->error("Profile provider [{$namespace}\\{$class}] already exists. Pass --force to overwrite.");

            return null;
        }

        $this->files->put($path, $this->renderProvider(
            namespace: $namespace,
            class: $class,
            id: $id,
        ));

        $this->components->task("Profile provider app/Vaulter/Profiles/{$class}.php");

        return $path;
    }

    private function renderProvider(string $namespace, string $class, string $id): string
    {
        $stub = $this->files->get($this->stubPath('profile-provider'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ id }}', '{{ storage_type }}', '{{ host }}', '{{ grants }}'],
            [$namespace, $class, $id, $this->resolveStorageType(), $this->renderHost(), $this->renderGrants()],
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
        $providerFqcn = "App\\Vaulter\\Profiles\\{$studly}Profile";

        $this->newLine();
        $this->components->info("Profile [{$id}] scaffolded.");
        $this->line('Next steps:');
        $this->line("  1. Register the provider in <info>config/vaulter.php</info> under 'panels':");
        $this->line("         \\{$providerFqcn}::class,");
    }

    private function stubPath(string $name): string
    {
        return __DIR__."/../../stubs/profile/{$name}.stub";
    }
}
