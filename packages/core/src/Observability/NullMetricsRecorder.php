<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;

#[Internal]
final class NullMetricsRecorder implements MetricsRecorder
{
    /** @param array<string, bool|int|float|string> $labels */
    public function counter(string $name, int|float $amount = 1, array $labels = []): void {}

    /** @param array<string, bool|int|float|string> $labels */
    public function gauge(string $name, int|float $value, array $labels = []): void {}

    /** @param array<string, bool|int|float|string> $labels */
    public function histogram(string $name, int|float $value, array $labels = []): void {}

    /** @param array<string, bool|int|float|string> $labels */
    public function timing(string $name, callable $operation, array $labels = []): mixed
    {
        return $operation();
    }
}
