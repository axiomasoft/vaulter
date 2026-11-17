<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Observability;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host-facing metrics seam. Package code depends only on this
 * contract; the host or load SUT binds a real adapter. With no backend installed the
 * container resolves {@see NullMetricsRecorder}.
 */
#[Spi(SpiKind::Implement)]
interface MetricsRecorder
{
    /**
     * @param array<string, bool|int|float|string> $labels
     */
    public function counter(string $name, int|float $amount = 1, array $labels = []): void;

    /**
     * @param array<string, bool|int|float|string> $labels
     */
    public function gauge(string $name, int|float $value, array $labels = []): void;

    /**
     * @param array<string, bool|int|float|string> $labels
     */
    public function histogram(string $name, int|float $value, array $labels = []): void;

    /**
     * @template T
     *
     * @param  callable(): T                        $operation
     * @param  array<string, bool|int|float|string> $labels
     * @return T
     */
    public function timing(string $name, callable $operation, array $labels = []): mixed;
}
