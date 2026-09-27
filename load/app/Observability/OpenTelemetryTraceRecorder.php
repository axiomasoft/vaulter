<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vaulter\Contracts\Observability\TraceRecorder;

/**
 * Load-host OpenTelemetry adapter (VLT-LOAD P8.11 / §20).
 *
 * This class is the only place the load proof imports the SDK. Package code keeps
 * using {@see TraceRecorder}; a missing SDK or a failed adapter resolves Null.
 */
final class OpenTelemetryTraceRecorder implements TraceRecorder
{
    private const array TARGETS = [
        'tree.read',
        'tree.move',
        'upload.tus',
        'upload.s3',
        'download.stream',
        'cas.commit',
        'cas.gc',
        'upload.sweep',
        'webhook.pipeline',
        'collaboration.session',
        'collaboration.snapshot',
    ];

    private const array LAYERS = ['controller', 'action', 'repository', 'command', 'job'];

    private const array OUTCOMES = [
        'success',
        'conflict',
        'validation',
        'dependency_timeout',
        'dependency_error',
        'internal_error',
        'cancelled',
    ];

    private const array PROTOCOLS = ['tus', 's3', 'single'];

    private const array SIZE_BUCKETS = ['le_8mib', 'le_64mib', 'le_512mib', 'le_5gib'];

    private const array TIERS = ['S0', 'S', 'M', 'L'];

    private bool $shutDown = false;

    private int $shutdownCount = 0;

    private ?int $lastDiagnosticNs = null;

    public function __construct(
        private readonly TracerProvider $provider,
        private readonly TracerInterface $tracer,
    ) {}

    public static function create(
        ?SpanExporterInterface $exporter = null,
        ?float $ratio = null,
        ?string $exportPath = null,
    ): self {
        $resolvedRatio = self::ratio($ratio);
        $resolvedExporter = $exporter ?? new RunScopedSpanExporter(self::exportPath($exportPath));
        $provider = new TracerProvider(
            spanProcessors: new BatchSpanProcessor(
                exporter: $resolvedExporter,
                clock: Clock::getDefault(),
                autoFlush: false,
            ),
            sampler: new ParentBased(new TraceIdRatioBasedSampler($resolvedRatio)),
            resource: ResourceInfoFactory::emptyResource(),
        );

        return new self(
            provider: $provider,
            tracer: $provider->getTracer('vaulter.load'),
        );
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function span(string $name, callable $operation, array $attributes = []): mixed
    {
        $span = $this->startInternal(name: $name, attributes: $attributes);
        $scope = $span->activate();

        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->markUncaught(span: $span);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Laravel middleware entry. Owns the HTTP SERVER span and W3C extraction.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return $this->traceServer(request: $request, next: $next);
    }

    public function traceServer(Request $request, Closure $next): mixed
    {
        $carrier = [
            'traceparent' => $request->headers->get('traceparent'),
            'tracestate' => $request->headers->get('tracestate'),
        ];
        $parent = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
        $span = $this->tracer->spanBuilder('vaulter.http.server')
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scope = $span->activate();

        try {
            $response = $next($request);
            if ($response instanceof Response && $response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $exception) {
            $this->markUncaught(span: $span);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function forceFlush(): void
    {
        try {
            if ($this->provider->forceFlush() === false) {
                $this->diagnose(phase: 'flush');
            }
        } catch (Throwable) {
            $this->diagnose(phase: 'flush');
        }
    }

    public function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }

        $this->shutDown = true;
        $this->shutdownCount++;

        try {
            if ($this->provider->shutdown() === false) {
                $this->diagnose(phase: 'shutdown');
            }
        } catch (Throwable) {
            $this->diagnose(phase: 'shutdown');
        }
    }

    public function shutdownCount(): int
    {
        return $this->shutdownCount;
    }

    /**
     * Fixed selected-span workload used by the tier-S overhead proof.
     *
     * @return array{ratio: float, iterations: int, p95_ns: int, peak_bytes: int, exported: int}
     */
    public static function sampleWorkload(float $ratio, int $iterations = 200): array
    {
        $exporter = new InMemoryExporter;
        $recorder = self::create(exporter: $exporter, ratio: $ratio);
        $buffer = str_repeat('a', 1_048_576);
        $samples = [];

        for ($index = 0; $index < $iterations; $index++) {
            $started = hrtime(true);
            $recorder->span(
                name: 'vaulter.action.upload.sweep',
                operation: static fn (): string => hash('sha256', $buffer),
                attributes: [
                    'vaulter.target' => 'upload.sweep',
                    'vaulter.layer' => 'action',
                ],
            );
            $samples[] = hrtime(true) - $started;
        }

        $recorder->forceFlush();
        $recorder->shutdown();
        sort($samples);

        return [
            'ratio' => $ratio,
            'iterations' => $iterations,
            'p95_ns' => $samples[(int) floor(0.95 * ($iterations - 1))],
            'peak_bytes' => memory_get_peak_usage(true),
            'exported' => count($exporter->getSpans()),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, scalar>
     */
    public static function allowlisted(array $attributes): array
    {
        $allowed = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || !self::accepted($key, $value)) {
                continue;
            }

            $allowed[$key] = $value;
        }

        ksort($allowed);

        return $allowed;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function startInternal(string $name, array $attributes): SpanInterface
    {
        $builder = $this->tracer->spanBuilder($name)->setSpanKind(SpanKind::KIND_INTERNAL);

        foreach (self::allowlisted($attributes) as $key => $value) {
            $builder = $builder->setAttribute($key, $value);
        }

        return $builder->startSpan();
    }

    private function markUncaught(SpanInterface $span): void
    {
        $span->setAttribute('vaulter.outcome', 'internal_error');
        $span->setStatus(StatusCode::STATUS_ERROR);
    }

    private function diagnose(string $phase): void
    {
        $now = hrtime(true);

        if ($this->lastDiagnosticNs !== null && ($now - $this->lastDiagnosticNs) < 1_000_000_000) {
            return;
        }

        $this->lastDiagnosticNs = $now;
        Log::warning('Vaulter trace export diagnostic.', ['phase' => $phase]);
    }

    private static function ratio(?float $ratio): float
    {
        $value = $ratio ?? (float) config('vaulter.observability.tracing.ratio', 0.01);

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private static function exportPath(?string $exportPath): string
    {
        if (is_string($exportPath) && $exportPath !== '') {
            return $exportPath;
        }

        $fromEnv = getenv('LOAD_TRACE_EXPORT');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return function_exists('storage_path')
            ? storage_path('logs/vaulter-trace.jsonl')
            : sys_get_temp_dir().'/vaulter-trace.jsonl';
    }

    private static function accepted(string $key, bool|int|float|string $value): bool
    {
        return match ($key) {
            'vaulter.target' => is_string($value) && in_array($value, self::TARGETS, true),
            'vaulter.layer' => is_string($value) && in_array($value, self::LAYERS, true),
            'vaulter.outcome' => is_string($value) && in_array($value, self::OUTCOMES, true),
            'vaulter.protocol' => is_string($value) && in_array($value, self::PROTOCOLS, true),
            'vaulter.size_bucket' => is_string($value) && in_array($value, self::SIZE_BUCKETS, true),
            'vaulter.tier' => is_string($value) && in_array($value, self::TIERS, true),
            'vaulter.correlation_slot' => is_string($value) && preg_match('/^slot-(0\d{2}|1\d{2}|2[0-4]\d|25[0-5])$/', $value) === 1,
            default => false,
        };
    }
}
