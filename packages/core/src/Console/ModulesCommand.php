<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vaulter\Kernel;
use Vaulter\Modules\Activation;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ModuleState;

/**
 * Lists the offered modules: package, protocol, activation policy, whether each is active and why, the
 * schema migrations not applied yet, and what it defines and contributes. Exit 1 while the module graph
 * is invalid (the reasons are printed); registered whatever the graph.
 */
final class ModulesCommand extends Command
{
    protected $signature = 'vaulter:modules
        {--json : Print one JSON document}';

    protected $description = 'List the Vaulter modules, their activation and what they contribute';

    public function handle(Kernel $kernel, Application $app): int
    {
        if (!$kernel->isValid()) {
            return $this->invalid($kernel->reasons());
        }
        $compiled = $kernel->compiled();
        $modules = array_map(fn (ModuleState $module): array => $this->describe($module, $compiled, $app), $compiled->modules);

        if ($this->option('json') === true) {
            $this->output->writeln(json_encode(['valid' => true, 'fingerprint' => $compiled->fingerprint, 'reasons' => [], 'modules' => $modules], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->table(
            ['Module', 'Package', 'Protocol', 'Policy', 'Active', 'Schema pending', 'Capabilities', 'Slots'],
            array_map(static fn (array $m): array => [
                $m['id'],
                $m['package'] ?? '-',
                $m['protocol'],
                $m['policy'],
                ($m['active'] ? 'yes' : 'no').' ('.$m['reason'].')',
                $m['schema_pending'] === null ? 'unknown' : (string) count($m['schema_pending']),
                (string) count($m['capabilities']),
                implode(', ', array_map(static fn (array $s): string => $s['slot'].' +'.$s['contributions'], $m['slots'])) ?: '-',
            ], $modules),
        );
        $this->line('<fg=gray>fingerprint '.$compiled->fingerprint.'</>');

        return self::SUCCESS;
    }

    /** @param list<string> $reasons */
    private function invalid(array $reasons): int
    {
        if ($this->option('json') === true) {
            $this->output->writeln(json_encode(['valid' => false, 'fingerprint' => null, 'reasons' => $reasons, 'modules' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        $this->line('<fg=red>The module graph is invalid, no module is active.</>');
        foreach ($reasons as $reason) {
            $this->line('  - '.OutputFormatter::escape($reason));
        }

        return self::FAILURE;
    }

    /**
     * @return array{id: string, manifest: string, package: string|null, protocol: string, policy: string, active: bool, reason: string, schema_pending: list<string>|null, capabilities: list<array{contract: string, role: string}>, slots: list<array{slot: string, role: string, contributions: int}>}
     */
    private function describe(ModuleState $module, CompiledModules $compiled, Application $app): array
    {
        $id = $module->id->value;
        $capabilities = [];
        foreach ($compiled->capabilities as $contract => $definition) {
            if ($definition->module === $id) {
                $capabilities[] = ['contract' => $contract, 'role' => 'defines'];
            }
        }
        foreach ($compiled->providers as $contract => $providers) {
            foreach ($providers as $provider) {
                if ($provider->module === $id) {
                    $capabilities[] = ['contract' => $contract, 'role' => 'provides'];
                }
            }
        }
        $slots = [];
        foreach ($compiled->slots as $contract => $slot) {
            if ($slot->module === $id) {
                $slots[$contract] = ['slot' => $contract, 'role' => 'defines', 'contributions' => 0];
            }
        }
        foreach ($compiled->contributions as $contract => $contributions) {
            foreach ($contributions as $contribution) {
                if ($contribution->module === $id) {
                    $slots[$contract] ??= ['slot' => $contract, 'role' => 'contributes', 'contributions' => 0];
                    $slots[$contract]['contributions']++;
                }
            }
        }
        ksort($slots, SORT_STRING);

        return [
            'id' => $id,
            'manifest' => $module->manifest,
            'package' => self::package($module->manifest),
            'protocol' => $module->version,
            'policy' => $module->activation === Activation::Auto ? 'auto' : 'opt-in',
            'active' => $module->active,
            'reason' => $module->activeReason,
            'schema_pending' => self::pendingMigrations($module->schemaPaths, $app),
            'capabilities' => $capabilities,
            'slots' => array_values($slots),
        ];
    }

    /**
     * The Composer package whose install path holds the manifest; looked up only when the command runs.
     *
     * @param class-string $manifest
     */
    private static function package(string $manifest): ?string
    {
        try {
            $file = (string) (new ReflectionClass($manifest))->getFileName();
            $best = null;
            $bestLength = 0;
            foreach (InstalledVersions::getInstalledPackages() as $package) {
                $path = InstalledVersions::getInstallPath($package);
                $real = $path === null ? false : realpath($path);
                if ($real !== false && str_starts_with($file, $real.DIRECTORY_SEPARATOR) && strlen($real) > $bestLength) {
                    $best = $package;
                    $bestLength = strlen($real);
                }
            }

            return $best;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The migrations of the module's schema that the migrator has not run; null when it cannot tell (no
     * database connection, no migrations table yet counts as all pending).
     *
     * @param  list<string>      $paths
     * @return list<string>|null
     */
    private static function pendingMigrations(array $paths, Application $app): ?array
    {
        if ($paths === []) {
            return [];
        }
        try {
            $migrator = $app->make('migrator');
            $files = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $pending = array_values(array_diff($files, $ran));
            sort($pending, SORT_STRING);

            return $pending;
        } catch (Throwable) {
            return null;
        }
    }
}
