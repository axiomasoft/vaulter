<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Vaulter\Support\Config;

/**
 * Deterministic P6.2 queue fixtures. Counts are declared; cardinality rows are
 * not seeded here. Cleanup stays inside this fixture root.
 */
final class QueueFixtureSupport
{
    public static function fixturesRoot(): string
    {
        return storage_path('app/p6-queue-fixtures');
    }

    public static function fixtureDirectory(string $runPrefix, string $fixtureId): string
    {
        return self::fixturesRoot().'/'.self::sanitizeToken($runPrefix).'/'.self::sanitizeToken($fixtureId);
    }

    public static function sanitizeToken(string $value): string
    {
        $token = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';
        $token = trim($token, '-');

        return $token !== '' ? $token : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildManifest(string $fixtureId, string $runPrefix): array
    {
        $backoffAttempt = 1;
        $backoffSeconds = Config::webhookBackoff($backoffAttempt);
        $maxAttempts = Config::webhookMaxAttempts();
        $disableAfter = Config::webhookDisableAfterFailures();
        $deliveryId = self::eventId($runPrefix, $fixtureId, 'duplicate-delivery', 1);

        return [
            'fixture_id' => $fixtureId,
            'run_prefix' => $runPrefix,
            'fanout_source' => 'deliveries_per_jobs',
            'performance_verdict' => false,
            'volumes' => [
                'tier' => 'S',
                'workers' => 2,
                'outbox_rows' => 10_000,
                'endpoints' => 20,
                'due_rows' => 5_000,
                'seeded' => false,
            ],
            'ingress' => [
                '1x' => 20,
                '5x' => 100,
                '10x' => 200,
            ],
            'densities' => [
                '0' => ['subscribed_endpoints' => 0, 'event_id' => self::eventId($runPrefix, $fixtureId, 'density-0', 1)],
                '1' => ['subscribed_endpoints' => 1, 'event_id' => self::eventId($runPrefix, $fixtureId, 'density-1', 1)],
                'all' => ['subscribed_endpoints' => 20, 'event_id' => self::eventId($runPrefix, $fixtureId, 'density-all', 1)],
            ],
            'sinks' => ['2xx', '500', 'delay', 'hang'],
            'overlap' => [
                'relay_ticks' => 2,
                'retry_ticks' => 2,
                'barrier' => true,
                'throughput_claim' => false,
            ],
            'duplicates' => [
                'serial' => self::duplicateCase($runPrefix, $fixtureId, 'serial', $deliveryId, false),
                'concurrent' => self::duplicateCase($runPrefix, $fixtureId, 'concurrent', $deliveryId, true),
            ],
            'state_machine' => [
                'backoff' => [
                    'attempt' => $backoffAttempt,
                    'configured_backoff_seconds' => $backoffSeconds,
                    'recorded_before_force_due' => true,
                    'clock' => 'explicit',
                    'elapsed_time_slo' => false,
                    'force_due' => true,
                ],
                'dead' => [
                    'attempts' => $maxAttempts,
                    'status' => 'dead',
                    'clock' => 'explicit',
                    'elapsed_time_slo' => false,
                ],
                'disable' => [
                    'after_status' => 'dead',
                    'failure_count' => $disableAfter,
                    'is_active' => false,
                    'clock' => 'explicit',
                    'elapsed_time_slo' => false,
                ],
            ],
            'probes' => [
                self::probe('endpoints', 200),
                self::probe('endpoints', 1000),
                self::probe('due_rows', 50_000),
                self::probe('due_rows', 500_000),
            ],
            'mechanism_only' => [
                'name' => 'timeout-ge-retry-after',
                'queue' => 'load-timeout-control',
                'product_worker' => false,
                'product_slo' => false,
            ],
            'cleanup' => [
                'scope' => 'exact-run',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function verifyManifest(array $manifest): void
    {
        $fixtureId = (string) ($manifest['fixture_id'] ?? '');
        $runPrefix = (string) ($manifest['run_prefix'] ?? '');
        $rebuilt = self::buildManifest($fixtureId, $runPrefix);

        if ($rebuilt !== $manifest) {
            throw new RuntimeException('Fixture manifest is not the deterministic rebuild.');
        }

        $serial = $manifest['duplicates']['serial']['queue_uuids'] ?? [];
        $concurrent = $manifest['duplicates']['concurrent']['queue_uuids'] ?? [];
        if (!is_array($serial) || !is_array($concurrent) || count($serial) !== 2 || count($concurrent) !== 2) {
            throw new RuntimeException('Duplicate controls need two queue UUIDs.');
        }

        $uuids = [...$serial, ...$concurrent];
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Duplicate queue UUIDs must be distinct.');
        }

        if (($manifest['duplicates']['serial']['proves_concurrent_exactly_once'] ?? true) !== false) {
            throw new RuntimeException('Serial duplicate is not concurrent exactly-once proof.');
        }

        if (($manifest['state_machine']['backoff']['recorded_before_force_due'] ?? false) !== true) {
            throw new RuntimeException('Backoff delta must be recorded before the row is forced due.');
        }

        foreach ($manifest['probes'] as $probe) {
            if (!is_array($probe) || ($probe['snapshot_restore_before'] ?? false) !== true || ($probe['tier_ml_slo'] ?? true) !== false) {
                throw new RuntimeException('Cardinality probes require a snapshot restore and no tier M/L SLO.');
            }
        }
    }

    /**
     * @param  array<string, mixed> $report
     * @return list<string>
     */
    public static function integrityDefects(array $report): array
    {
        $defects = [];
        $expected = is_array($report['expected'] ?? null) ? $report['expected'] : [];
        $actual = is_array($report['actual'] ?? null) ? $report['actual'] : [];

        foreach (['delivery_rows', 'queue_jobs', 'sink_receives'] as $field) {
            $left = $expected[$field] ?? null;
            $right = $actual[$field] ?? null;
            if (!is_int($left) || !is_int($right)) {
                $defects[] = "invalid_{$field}";

                continue;
            }

            if ($right < $left) {
                $defects[] = "missing_{$field}";
            }

            if ($right > $left) {
                $defects[] = "extra_{$field}";
            }
        }

        $fields = is_array($report['sink_fields'] ?? null) ? $report['sink_fields'] : [];
        if (in_array('body', $fields, true) || in_array('payload', $fields, true)) {
            $defects[] = 'sink_recorded_body';
        }

        if (in_array('signature', $fields, true)) {
            $defects[] = 'sink_recorded_signature';
        }

        return $defects;
    }

    /**
     * @param list<string> $paths
     */
    public static function cleanupPaths(array $paths, string $directory): void
    {
        $root = realpath(self::fixturesRoot());

        foreach ($paths as $path) {
            $real = realpath($path) ?: $path;
            if ($root !== false && !str_starts_with($real, $root.DIRECTORY_SEPARATOR) && $real !== $root) {
                throw new RuntimeException("Refusing to delete [{$path}] outside the fixture root.");
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        $directoryReal = realpath($directory) ?: $directory;
        if (is_dir($directoryReal)) {
            File::deleteDirectory($directoryReal);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function duplicateCase(string $runPrefix, string $fixtureId, string $mode, string $deliveryId, bool $concurrent): array
    {
        return [
            'delivery_id' => $deliveryId,
            'queue_uuids' => [
                self::uuid($runPrefix.'|'.$fixtureId.'|'.$mode.'|1'),
                self::uuid($runPrefix.'|'.$fixtureId.'|'.$mode.'|2'),
            ],
            'shared_delivery' => true,
            'distinct_queue_uuids' => true,
            'concurrent' => $concurrent,
            'proves_concurrent_exactly_once' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function probe(string $kind, int $count): array
    {
        return [
            'kind' => $kind,
            'count' => $count,
            'attempt' => 1,
            'snapshot_restore_before' => true,
            'tier_ml_slo' => false,
            'seeded' => false,
        ];
    }

    public static function eventId(string $runPrefix, string $fixtureId, string $cell, int $ordinal): string
    {
        return substr(hash('sha256', $runPrefix.'|'.$fixtureId.'|'.$cell.'|'.$ordinal), 0, 32);
    }

    private static function uuid(string $seed): string
    {
        $hex = hash('sha256', $seed);
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
