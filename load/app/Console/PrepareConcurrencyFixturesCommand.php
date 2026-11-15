<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Disposable concurrency fixtures and post-run integrity checks.
 *
 * Generation is idempotent by fixture_id. Cleanup deletes that id or one
 * run-prefix snapshot and nothing outside the fixture root.
 */
final class PrepareConcurrencyFixturesCommand extends Command
{
    protected $signature = 'load:concurrency-fixtures
        {action : prepare|cleanup|verify|check-integrity}
        {--fixture-id= : Stable fixture id}
        {--run-prefix= : Disposable snapshot prefix}
        {--scope=p5 : CAS lock scope mixed into hashtext(scope|sha256)}
        {--report= : JSON integrity report for check-integrity}';

    protected $description = 'Prepare, verify, or clean P5 concurrency fixtures (small payloads, exact cleanup).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'prepare' => $this->prepare(),
            'cleanup' => $this->cleanup(),
            'verify' => $this->verify(),
            'check-integrity' => $this->checkIntegrity(),
            default => $this->invalid($action),
        };
    }

    private function prepare(): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');
        $scope = (string) $this->option('scope');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for prepare.');

            return self::FAILURE;
        }

        $directory = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
        $manifestPath = $directory.'/manifest.json';

        try {
            $manifest = ConcurrencyFixtureSupport::buildManifest($fixtureId, $runPrefix, $scope);
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

            ConcurrencyFixtureSupport::verifyManifest($manifest);
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
            ConcurrencyFixtureSupport::verifyManifest($manifest);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Fixture manifest re-verified (sha256 and hashtext).');

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

        $root = ConcurrencyFixtureSupport::fixturesRoot();
        $prefixDir = $root.'/'.ConcurrencyFixtureSupport::sanitizeToken($runPrefix);

        if ($fixtureId !== '') {
            $directory = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
            if (!is_dir($directory)) {
                $this->components->error("Fixture [{$fixtureId}] is not in snapshot [{$runPrefix}].");

                return self::FAILURE;
            }

            $manifest = $this->decodeFile($directory.'/manifest.json');
            $paths = is_array($manifest['cleanup']['paths'] ?? null) ? $manifest['cleanup']['paths'] : [];
            ConcurrencyFixtureSupport::cleanupPaths($paths, $directory);
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

    private function checkIntegrity(): int
    {
        $reportPath = (string) $this->option('report');
        if ($reportPath === '' || !is_file($reportPath)) {
            $this->components->error('--report must point at a JSON integrity report.');

            return self::FAILURE;
        }

        $size = filesize($reportPath);
        if ($size === false || $size > 1_048_576) {
            $this->components->error('Integrity report exceeds 1 MiB.');

            return self::FAILURE;
        }

        try {
            $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->components->error('Integrity report is not JSON.');

            return self::FAILURE;
        }

        if (!is_array($report)) {
            $this->components->error('Integrity report must be a JSON object.');

            return self::FAILURE;
        }

        $defects = ConcurrencyFixtureSupport::integrityDefects($report);
        if ($defects !== []) {
            $this->components->error('Integrity defects: '.implode(', ', $defects));

            return self::FAILURE;
        }

        $this->components->info('Integrity report has no blob, upload, tree, or sweeper defects.');

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

        $path = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';
        if (!is_file($path)) {
            $this->components->error("Manifest [{$path}] does not exist.");

            return null;
        }

        $manifest = $this->decodeFile($path);
        if ($manifest === []) {
            $this->components->error('Manifest is not a JSON object.');

            return null;
        }

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function invalid(string $action): int
    {
        $this->components->error("Unknown action [{$action}].");

        return self::INVALID;
    }
}
