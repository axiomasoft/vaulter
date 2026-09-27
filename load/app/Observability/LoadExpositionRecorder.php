<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * Load-host adapter: validates the frozen P8 vocabulary and writes to one open run.
 */
final class LoadExpositionRecorder implements MetricsRecorder
{
    private const int DIAGNOSTIC_INTERVAL_SECONDS = 60;

    /** @var array<string, float> */
    private static array $lastDiagnosticAt = [];

    /** @var Closure(): mixed */
    private readonly Closure $runIdResolver;

    /** @param (Closure(): mixed)|null $runIdResolver */
    public function __construct(
        private readonly RedisMetricsRegistry $registry,
        ?Closure $runIdResolver = null,
    ) {
        $this->runIdResolver = $runIdResolver ?? static fn (): ?string => $registry->activeRunId();
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function counter(string $name, int|float $amount = 1, array $labels = []): void
    {
        $this->record(type: 'counter', name: $name, value: $amount, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function gauge(string $name, int|float $value, array $labels = []): void
    {
        $this->record(type: 'gauge', name: $name, value: $value, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function histogram(string $name, int|float $value, array $labels = []): void
    {
        $this->record(type: 'histogram', name: $name, value: $value, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function timing(string $name, callable $operation, array $labels = []): mixed
    {
        $started = hrtime(true);

        try {
            return $operation();
        } finally {
            $this->histogram(
                name: $name,
                value: (hrtime(true) - $started) / 1_000_000_000,
                labels: $labels,
            );
        }
    }

    /** @param array<string, bool|int|float|string> $labels */
    private function record(string $type, string $name, int|float $value, array $labels): void
    {
        $runId = ($this->runIdResolver)();

        if (!is_string($runId) || trim($runId) === '') {
            $this->diagnose(reason: 'missing_run_id', name: $name);

            return;
        }

        try {
            if (!$this->registry->isOpen($runId)) {
                $this->diagnose(reason: 'run_not_open', name: $name);

                return;
            }

            $definition = $this->registry->definition($name);

            if ($definition === null || $definition['type'] !== $type) {
                $this->diagnose(reason: 'unknown_or_wrong_type', name: $name);

                return;
            }

            if (!is_finite((float) $value) || $value < 0 || ($type === 'counter' && $value <= 0)) {
                $this->diagnose(reason: 'invalid_numeric_value', name: $name);

                return;
            }

            $normalized = $this->normalizeLabels($labels);

            if (!$this->labelsAreAllowed($normalized, $definition['labels'])) {
                $this->diagnose(reason: 'invalid_labels', name: $name);

                return;
            }

            match ($type) {
                'counter' => $this->registry->incrementCounter($runId, $name, $value, $normalized),
                'gauge' => $this->registry->setGauge($runId, $name, $value, $normalized),
                'histogram' => $this->registry->observeHistogram($runId, $name, $value, $normalized),
            };
        } catch (Throwable $exception) {
            $this->diagnose(reason: 'backend_error', name: $name, exception: $exception);
        }
    }

    /** @param array<string, string> $labels
     *  @param array<string, list<string>> $vocabulary */
    private function labelsAreAllowed(array $labels, array $vocabulary): bool
    {
        $expectedKeys = array_keys($vocabulary);
        sort($expectedKeys, SORT_STRING);

        if (array_keys($labels) !== $expectedKeys) {
            return false;
        }

        foreach ($labels as $key => $value) {
            if (!in_array($value, $vocabulary[$key], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, bool|int|float|string> $labels
     *  @return array<string, string> */
    private function normalizeLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $key => $value) {
            $normalized[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function diagnose(string $reason, string $name, ?Throwable $exception = null): void
    {
        $key = $reason.':'.$name;
        $now = microtime(true);

        if (($now - (self::$lastDiagnosticAt[$key] ?? 0.0)) < self::DIAGNOSTIC_INTERVAL_SECONDS) {
            return;
        }

        self::$lastDiagnosticAt[$key] = $now;

        try {
            Log::warning('Vaulter load metric emission dropped.', [
                'reason' => $reason,
                'metric' => $name,
                'exception' => $exception?->getMessage(),
            ]);
        } catch (Throwable) {
            // Observability must never alter the business result, including logger failure.
        }
    }
}
