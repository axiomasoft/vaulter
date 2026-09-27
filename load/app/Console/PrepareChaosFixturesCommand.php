<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * P7.2 disposable upload/CAS/outbox fixtures with exact run-prefix cleanup.
 */
final class PrepareChaosFixturesCommand extends Command
{
    protected $signature = 'load:chaos-fixtures
        {action : prepare|cleanup|verify}
        {--fixture-id= : Stable fixture id}
        {--run-prefix= : Disposable snapshot prefix}';

    protected $description = 'Prepare, verify, or clean P7 chaos fixtures (deterministic ids, exact cleanup).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'prepare' => $this->prepare(),
            'cleanup' => $this->cleanup(),
            'verify' => $this->verify(),
            default => $this->invalid($action),
        };
    }

    private function prepare(): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for prepare.');

            return self::FAILURE;
        }

        $directory = ChaosFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
        $manifestPath = $directory.'/manifest.json';

        try {
            $manifest = ChaosFixtureSupport::buildManifest($fixtureId, $runPrefix);
            ChaosFixtureSupport::verifyManifest($manifest);
            $manifest['cleanup'] = [
                'scope' => 'run-prefix',
                'paths' => [$manifestPath],
            ];
            $encoded = $this->encode($manifest);
            $existing = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

            if ($existing === $encoded) {
                $this->components->info("Fixture [{$fixtureId}] already prepared — manifest reused.");

                return self::SUCCESS;
            }

            File::ensureDirectoryExists($directory);
            if (file_put_contents($manifestPath, $encoded) === false) {
                throw new RuntimeException("Unable to write manifest [{$manifestPath}].");
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Prepared fixture [{$fixtureId}] at {$directory}.");

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return self::FAILURE;
        }

        try {
            $paths = $manifest['cleanup']['paths'] ?? null;
            unset($manifest['cleanup']);
            if (!is_array($paths) || $paths === []) {
                throw new RuntimeException('Fixture cleanup paths are missing.');
            }
            ChaosFixtureSupport::verifyManifest($manifest);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Fixture manifest re-verified (ids, part keys, cleanup).');

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($runPrefix === '') {
            $this->components->error('--run-prefix is required for cleanup.');

            return self::FAILURE;
        }

        $root = ChaosFixtureSupport::fixturesRoot();
        $prefixDir = $root.'/'.ChaosFixtureSupport::sanitizeToken($runPrefix);

        if ($fixtureId !== '') {
            $directory = ChaosFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
            if (!is_dir($directory)) {
                $this->components->error("Fixture [{$fixtureId}] is not in snapshot [{$runPrefix}].");

                return self::FAILURE;
            }

            $manifest = $this->decodeFile($directory.'/manifest.json');
            $paths = is_array($manifest['cleanup']['paths'] ?? null) ? $manifest['cleanup']['paths'] : [];
            ChaosFixtureSupport::cleanupPaths($paths, $directory);
            $this->components->info("Removed fixture [{$fixtureId}].");

            return self::SUCCESS;
        }

        if (!is_dir($prefixDir)) {
            $this->components->error("Snapshot [{$runPrefix}] does not exist.");

            return self::FAILURE;
        }

        $realRoot = realpath($root);
        $realPrefix = realpath($prefixDir);
        if ($realRoot === false || $realPrefix === false || !str_starts_with($realPrefix, $realRoot.DIRECTORY_SEPARATOR)) {
            $this->components->error('Refusing to delete a path outside the fixture root.');

            return self::FAILURE;
        }

        File::deleteDirectory($realPrefix);
        $this->components->info("Removed snapshot [{$runPrefix}].");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(): ?array
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');
        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required.');

            return null;
        }

        $path = ChaosFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';
        if (!is_file($path)) {
            $this->components->error("Fixture manifest [{$path}] is missing.");

            return null;
        }

        return $this->decodeFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $path): array
    {
        try {
            return $this->decode((string) file_get_contents($path));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return [];
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Fixture manifest is not JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Fixture manifest must be a JSON object.');
        }

        return $decoded;
    }

    private function invalid(string $action): int
    {
        $this->components->error("Unknown action [{$action}].");

        return self::FAILURE;
    }
}
