<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;

/**
 * Scaffolds a host-owned permission driver with a Pest test on its contract suite. Registration stays
 * explicit: the command prints the config line instead of editing the host's config.
 */
#[Internal]
final class MakePermissionDriverCommand extends Command
{
    protected $signature = 'vaulter:make:permission-driver
        {name : The permission driver name (StudlyCase or kebab-case), e.g. }
        {--force : Overwrite the class and its test if they already exist}';

    protected $description = 'Scaffold a Vaulter permission driver with a contract test';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $class = Str::studly($rawName);
        $slug = Str::kebab($class);
        if ($class === '' || preg_match('/^[a-z0-9-]+$/D', $slug) !== 1) {
            $this->components->error('The permission driver name must contain letters, digits or hyphens.');

            return self::FAILURE;
        }

        $namespace = 'App\\Vaulter\\PermissionDrivers';
        $classPath = sprintf('%s/%s.php', app_path('Vaulter/PermissionDrivers'), $class);
        $testPath = sprintf('%s/%sTest.php', base_path('tests/Unit/Vaulter'), $class);
        $force = (bool) $this->option('force');
        foreach ([$classPath, $testPath] as $path) {
            if (!$force && $this->files->exists($path)) {
                $this->components->error(sprintf('[%s] already exists. Pass --force to overwrite.', $path));

                return self::FAILURE;
            }
        }

        $replace = [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ fqcn }}' => $namespace.'\\'.$class,
            '{{ key }}' => 'app/'.$slug,
            '{{ slug }}' => $slug,
            '{{ extension }}' => str_replace('-', '', $slug),
        ];
        $this->write($classPath, 'permission-driver', $replace);
        $this->write($testPath, 'permission-driver-test', $replace);

        $this->components->task(sprintf('permission driver app/Vaulter/PermissionDrivers/%s.php', $class));
        $this->components->task(sprintf('Test tests/Unit/Vaulter/%sTest.php', $class));
        $this->newLine();
        $this->line("Add it to <info>config/vaulter-drive.php</info> under 'access.permission_drivers' (key => class):");
        $this->line(sprintf("    '%s' => \\%s::class,", $slug, $namespace.'\\'.$class));

        return self::SUCCESS;
    }

    /** @param array<string, string> $replace */
    private function write(string $path, string $stub, array $replace): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, strtr($this->files->get(__DIR__.sprintf('/../../stubs/%s.stub', $stub)), $replace));
    }
}
