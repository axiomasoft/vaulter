<?php

declare(strict_types=1);

namespace Vaulter\Testing;

use RuntimeException;
use Throwable;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * In-memory {@see MetricsRecorder} for package and host tests. Lives in
 * src/Testing/ so consumers can assert emissions without a metrics backend.
 */
final class FakeMetricsRecorder implements MetricsRecorder
{
    /** @var list<array{type: string, name: string, value: int|float, labels: array<string, string>}> */
    private array $emissions = [];

    /** @param array<string, bool|int|float|string> $labels */
    public function counter(string $name, int|float $amount = 1, array $labels = []): void
    {
        $this->push(type: 'counter', name: $name, value: $amount, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function gauge(string $name, int|float $value, array $labels = []): void
    {
        $this->push(type: 'gauge', name: $name, value: $value, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function histogram(string $name, int|float $value, array $labels = []): void
    {
        $this->push(type: 'histogram', name: $name, value: $value, labels: $labels);
    }

    /** @param array<string, bool|int|float|string> $labels */
    public function timing(string $name, callable $operation, array $labels = []): mixed
    {
        $started = hrtime(true);

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->push(
                type: 'histogram',
                name: $name,
                value: (hrtime(true) - $started) / 1_000_000_000,
                labels: $labels,
            );

            throw $exception;
        }

        $this->push(
            type: 'histogram',
            name: $name,
            value: (hrtime(true) - $started) / 1_000_000_000,
            labels: $labels,
        );

        return $result;
    }

    /**
     * @return list<array{type: string, name: string, value: int|float, labels: array<string, string>}>
     */
    public function emissions(): array
    {
        return $this->emissions;
    }

    /** @param array<string, bool|int|float|string>|null $labels */
    public function assertCounter(string $name, ?array $labels = null): void
    {
        $this->assertType(type: 'counter', name: $name, labels: $labels);
    }

    /** @param array<string, bool|int|float|string>|null $labels */
    public function assertGauge(string $name, ?array $labels = null): void
    {
        $this->assertType(type: 'gauge', name: $name, labels: $labels);
    }

    /** @param array<string, bool|int|float|string>|null $labels */
    public function assertHistogram(string $name, ?array $labels = null): void
    {
        $this->assertType(type: 'histogram', name: $name, labels: $labels);
    }

    /** @param array<string, bool|int|float|string>|null $labels */
    private function assertType(string $type, string $name, ?array $labels): void
    {
        $normalized = $labels === null ? null : $this->normalizeLabels($labels);

        foreach ($this->emissions as $emission) {
            if ($emission['type'] !== $type || $emission['name'] !== $name) {
                continue;
            }

            if ($normalized !== null && $emission['labels'] !== $normalized) {
                continue;
            }

            return;
        }

        throw new RuntimeException(sprintf(
            'Expected %s emission for %s%s',
            $type,
            $name,
            $normalized === null ? '' : ' with labels '.json_encode($normalized, JSON_THROW_ON_ERROR),
        ));
    }

    /** @param array<string, bool|int|float|string> $labels */
    private function push(string $type, string $name, int|float $value, array $labels): void
    {
        $this->emissions[] = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'labels' => $this->normalizeLabels($labels),
        ];
    }

    /**
     * @param  array<string, bool|int|float|string> $labels
     * @return array<string, string>
     */
    private function normalizeLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $key => $value) {
            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';

                continue;
            }

            $normalized[$key] = (string) $value;
        }

        ksort($normalized);

        return $normalized;
    }
}
