<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Closure;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationMiddleware;

/**
 * The kernel's own operation middleware (`core.telemetry`): one span per operation and the
 * `vaulter_operation_duration_seconds` / `vaulter_operations_total{operation,outcome}` metrics. Its
 * attributes come from {@see OperationContext::telemetry()}: no personal data,
 * never an idempotency key. Outcome: `ok`, `rejected` (a problem below 500) or `error`.
 */
#[Internal]
final readonly class TelemetryMiddleware implements OperationMiddleware
{
    public const string SPAN = 'vaulter.operation';

    public const string DURATION = 'vaulter_operation_duration_seconds';

    public const string TOTAL = 'vaulter_operations_total';

    public function __construct(
        private MetricsRecorder $metrics,
        private TraceRecorder $tracer,
    ) {}

    public function handle(Operation $operation, Closure $next): mixed
    {
        $name = $operation->spec->name;
        $attributes = [
            'vaulter.operation' => $name,
            'vaulter.module' => $operation->spec->module->value,
            'vaulter.operation.nested' => $operation->isNested(),
            ...$operation->context->telemetry(),
        ];
        $startedAt = hrtime(true);
        $outcome = 'error';

        try {
            $result = $this->tracer->span(self::SPAN, static fn (): mixed => $next($operation), $attributes);
            $outcome = 'ok';

            return $result;
        } catch (Throwable $exception) {
            $outcome = $exception instanceof ProblemException && $exception->httpStatus() < 500 ? 'rejected' : 'error';

            throw $exception;
        } finally {
            $this->metrics->histogram(name: self::DURATION, value: (hrtime(true) - $startedAt) / 1_000_000_000, labels: ['operation' => $name, 'outcome' => $outcome]);
            $this->metrics->counter(name: self::TOTAL, labels: ['operation' => $name, 'outcome' => $outcome]);
        }
    }
}
