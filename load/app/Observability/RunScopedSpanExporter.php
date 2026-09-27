<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\Behavior\SpanExporterTrait;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use RuntimeException;

/**
 * Run-scoped JSONL exporter for the P8.11 host proof.
 *
 * Each line carries trace/span ids, kind, status and the closed attribute allowlist.
 * Exception events, resource attributes and product identifiers are not written.
 */
final class RunScopedSpanExporter implements SpanExporterInterface
{
    use SpanExporterTrait;

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * @param iterable<SpanDataInterface> $spans
     */
    protected function doExport(iterable $spans): bool
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $handle = fopen($this->path, 'ab');

        if ($handle === false) {
            return false;
        }

        try {
            foreach ($spans as $span) {
                if ($span->getEvents() !== []) {
                    return false;
                }

                $encoded = json_encode(self::row($span), JSON_THROW_ON_ERROR);
                if (fwrite($handle, $encoded."\n") === false) {
                    return false;
                }
            }
        } catch (RuntimeException) {
            return false;
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * @return array{
     *     trace_id: string,
     *     span_id: string,
     *     parent_span_id: ?string,
     *     name: string,
     *     kind: string,
     *     status: string,
     *     attributes: array<string, scalar>
     * }
     */
    public static function row(SpanDataInterface $span): array
    {
        $parent = $span->getParentSpanId();

        return [
            'trace_id' => $span->getTraceId(),
            'span_id' => $span->getSpanId(),
            'parent_span_id' => ($parent === '' || preg_match('/^0+$/', $parent) === 1) ? null : $parent,
            'name' => $span->getName(),
            'kind' => match ($span->getKind()) {
                SpanKind::KIND_SERVER => 'SERVER',
                SpanKind::KIND_INTERNAL => 'INTERNAL',
                default => 'OTHER',
            },
            'status' => $span->getStatus()->getCode() === StatusCode::STATUS_ERROR ? 'ERROR' : 'UNSET',
            'attributes' => OpenTelemetryTraceRecorder::allowlisted($span->getAttributes()->toArray()),
        ];
    }
}
