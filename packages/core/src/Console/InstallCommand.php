<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use Vaulter\Attributes\Internal;

/**
 * Publishes the config of every installed Vaulter package and prints the host
 * settings it will not touch: host config and .env stay under the host's control.
 */
#[Internal]
final class InstallCommand extends Command
{
    private const string DEFAULT_MIDDLEWARE = 'auth:sanctum';

    protected $signature = 'vaulter:install
        {--middleware= : Auth middleware for the authenticated route stack, e.g. auth:sanctum}
        {--migrate : Run the migrations without asking}
        {--force : Overwrite config files that are already published}';

    protected $description = 'Publish Vaulter config, show the host settings to apply, optionally migrate and run the doctor';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();
        $middleware = $this->option('middleware');
        if ($middleware !== null && preg_match('/^[A-Za-z0-9_\\\\.:,\-]+$/D', $middleware) !== 1) {
            $this->components->error('Invalid --middleware value.');

            return self::FAILURE;
        }

        foreach ($this->configTags() as $tag) {
            $this->publish($tag);
        }

        if ($interactive) {
            $middleware ??= (string) select('Auth middleware for the authenticated route stack', [self::DEFAULT_MIDDLEWARE, 'auth', 'auth:api', 'auth:web'], self::DEFAULT_MIDDLEWARE);
        }
        $this->hostSettings($middleware ?? self::DEFAULT_MIDDLEWARE);

        if ($this->option('migrate') === true || ($interactive && confirm('Run the migrations now?', false))) {
            $this->call('migrate');
        } else {
            $this->components->info('Migrations not run; run `php artisan migrate` when ready.');
        }

        // The install is not a gate: a finding is reported, the exit code stays 0.
        $this->newLine();
        $this->call('vaulter:doctor');

        if ($interactive && confirm('Star axiomasoft/vaulter on GitHub?', false)) {
            $this->line('https://github.com/axiomasoft/vaulter');
        }

        return self::SUCCESS;
    }

    /**
     * Config publish tags of the installed Vaulter packages: a package that is not
     * installed registers none.
     *
     * @return list<string>
     */
    private function configTags(): array
    {
        $tags = [];
        foreach (ServiceProvider::publishableGroups() as $group) {
            if (is_string($group) && preg_match('/^vaulter(-[a-z]+)?-config$/D', $group) === 1) {
                $tags[] = $group;
            }
        }
        sort($tags);

        return $tags;
    }

    private function publish(string $tag): void
    {
        foreach (ServiceProvider::pathsToPublish(null, $tag) as $from => $to) {
            if (!is_string($to)) {
                continue;
            }
            $exists = $this->files->exists($to);
            if ($exists && $this->option('force') !== true) {
                $this->components->twoColumnDetail($to, '<fg=yellow>skipped</>');

                continue;
            }
            $this->files->ensureDirectoryExists(dirname($to));
            $this->files->copy($from, $to);
            $this->components->twoColumnDetail($to, '<fg=green>published</>');
        }
    }

    private function hostSettings(string $middleware): void
    {
        $this->newLine();
        $this->line('Apply these yourself; the installer does not edit config or .env:');
        $this->line("  config/vaulter.php:        routes.middleware.authenticated => ['api', '".$middleware."']");
        $this->newLine();
    }
}
