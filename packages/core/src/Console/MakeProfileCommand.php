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
        $id = Str::kebab(str_replace('_', '-', $rawName));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $id) !== 1) {
            $this->components->error('Profile key must contain 1..64 lowercase letters, digits or hyphens.');

            return self::FAILURE;
        }
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
        $class = $studly.'Profile';
        $directory = app_path('Vaulter/Profiles');
        $this->files->ensureDirectoryExists($directory);

        $path = sprintf('%s/%s.php', $directory, $class);

        if (!$force && $this->files->exists($path)) {
            $this->components->error(sprintf('Profile provider [%s\%s] already exists. Pass --force to overwrite.', $namespace, $class));

            return null;
        }

        $this->files->put($path, $this->renderProvider(
            namespace: $namespace,
            class: $class,
            id: $id,
        ));

        $this->components->task(sprintf('Profile provider app/Vaulter/Profiles/%s.php', $class));

        return $path;
    }

    private function renderProvider(string $namespace, string $class, string $id): string
    {
        $stub = $this->files->get($this->stubPath('profile-provider'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ id }}', '{{ host }}', '{{ grants }}'],
            [$namespace, $class, $id, $this->renderHost(), $this->renderGrants()],
            $stub,
        );
    }

    private function renderHost(): string
    {
        $host = $this->option('host');

        if (!is_string($host) || $host === '') {
            return '';
        }

        return PHP_EOL.'            ->owners(\\'.ltrim($host, '\\').'::class)';
    }

    private function renderGrants(): string
    {
        return (bool) $this->option('no-grants')
            ? PHP_EOL.'            ->grants(false)'
            : '';
    }

    private function printNextSteps(string $studly, string $id): void
    {
        $providerFqcn = sprintf('App\Vaulter\Profiles\%sProfile', $studly);

        $this->newLine();
        $this->components->info(sprintf('Profile [%s] scaffolded.', $id));
        $this->line('Next steps:');
        $this->line("  1. Register the provider in <info>config/vaulter.php</info> under 'profiles.providers':");
        $this->line(sprintf('         \%s::class,', $providerFqcn));
    }

    private function stubPath(string $name): string
    {
        return __DIR__.sprintf('/../../stubs/profile/%s.stub', $name);
    }
}
