<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Vaulter\Contracts\Cas\ContentStore;

/**
 * P4.2 — isolated CAS stage probe (CLI process, not FPM).
 */
final class ProbeCasStageMemoryCommand extends Command
{
    protected $signature = 'load:memory-probe-cas-stage
        {--fixture-id= : Fixture id (with --run-prefix)}
        {--run-prefix= : Run prefix}
        {--path= : Direct payload path (overrides manifest lookup)}
        {--ceiling-mib=64 : Declared PHP peak ceiling for this probe}';

    protected $description = 'Stage a fixture through Sha256ContentStore::stage() and report bounded peak memory.';

    public function handle(ContentStore $contentStore): int
    {
        $path = $this->resolvePayloadPath();

        if ($path === null) {
            return self::FAILURE;
        }

        $peakBefore = memory_get_peak_usage(true);
        $stagedSha = null;
        $stageDurationSeconds = null;

        try {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Unable to open payload [{$path}].");
            }

            $stageStartedAt = hrtime(true);
            $staged = $contentStore->stage($handle);
            $stageDurationSeconds = (hrtime(true) - $stageStartedAt) / 1_000_000_000;
            fclose($handle);
            $stagedSha = $staged->sha256();
            $staged->discard();
        } catch (\Throwable $throwable) {
            throw $throwable;
        }

        $peakAfter = memory_get_peak_usage(true);
        $delta = max(0, $peakAfter - $peakBefore);
        $ceilingBytes = (int) $this->option('ceiling-mib') * 1024 * 1024;
        $sha256 = MemoryFixtureSupport::hashFileBounded($path);

        $payload = [
            'process_kind' => 'php_cli',
            'pid' => getmypid(),
            'php_peak_bytes' => $peakAfter,
            'php_peak_delta_bytes' => $delta,
            'ceiling_bytes' => $ceilingBytes,
            'within_ceiling' => $peakAfter < $ceilingBytes,
            'stage_duration_seconds' => $stageDurationSeconds,
            'staged_sha256' => $stagedSha,
            'probe_object_sha256' => $sha256,
            'checksum_match' => $stagedSha === $sha256,
        ];

        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $payload['within_ceiling'] && $payload['checksum_match'] ? self::SUCCESS : self::FAILURE;
    }

    private function resolvePayloadPath(): ?string
    {
        $direct = (string) $this->option('path');

        if ($direct !== '') {
            if (!is_file($direct)) {
                $this->components->error("Payload not found [{$direct}].");

                return null;
            }

            return $direct;
        }

        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('Pass --path or both --fixture-id and --run-prefix.');

            return null;
        }

        $manifestPath = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';

        if (!is_file($manifestPath)) {
            $this->components->error("Manifest missing: {$manifestPath}");

            return null;
        }

        $manifest = MemoryFixtureSupport::readJsonFile($manifestPath);
        $path = (string) ($manifest['payload_path'] ?? '');

        if ($path === '' || !is_file($path)) {
            $this->components->error('Fixture payload_path missing on disk.');

            return null;
        }

        return $path;
    }
}
