<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Closure;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Contracts\Observability\TraceRecorder;

/**
 * Production OTLP HTTP/protobuf {@see TraceRecorder}.
 *
 * Buffers completed spans in bounded memory on the hot path and exports an
 * `ExportTraceServiceRequest` protobuf payload on `flush()` / Laravel terminating
 * with short bounded HTTP timeouts, failing open so telemetry never blocks or
 * breaks business operations.
 *
 * @phpstan-type ParsedTraceparent array{traceId: string, spanId: string, flags: int}
 * @phpstan-type SpanLink array{traceId: string, spanId: string, flags: int}
 * @phpstan-type RecordedSpan array{
 *     traceId: string,
 *     spanId: string,
 *     parentSpanId: ?string,
 *     name: string,
 *     kind: int,
 *     startTimeUnixNano: int,
 *     endTimeUnixNano: int,
 *     attributes: array<string, scalar>,
 *     links: list<SpanLink>,
 *     statusCode: int,
 *     statusMessage: string
 * }
 */
#[Internal]
final class OtlpOpenTelemetryTraceRecorder implements TraceRecorder
{
    public const string DEFAULT_ENDPOINT = 'http://127.0.0.1:4318/v1/traces';

    public const string DEFAULT_SERVICE_NAME = 'vaulter';

    public const int DEFAULT_TIMEOUT_MS = 500;

    public const int DEFAULT_MAX_BUFFERED_SPANS = 2048;

    private const int SPAN_KIND_INTERNAL = 1;

    private const int STATUS_UNSET = 0;

    private const int STATUS_OK = 1;

    private const int STATUS_ERROR = 2;

    /** @var list<array{traceId: string, spanId: string}> */
    private array $stack = [];

    /** @var ?ParsedTraceparent */
    private ?array $inboundContext = null;

    /** @var list<RecordedSpan> */
    private array $pendingSpans = [];

    private ?int $baseWallNano = null;

    private ?int $baseHrNano = null;

    /**
     * @param array<string, string>                                      $headers
     * @param ?Closure(string, string, array<string, string>, int): void $transport
     */
    public function __construct(
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly string $serviceName = self::DEFAULT_SERVICE_NAME,
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        private readonly array $headers = [],
        private readonly int $maxBufferedSpans = self::DEFAULT_MAX_BUFFERED_SPANS,
        private readonly ?Closure $transport = null,
        private readonly bool $flushOnDestruct = false,
    ) {}

    public static function fromEnvironment(): self
    {
        $endpoint = self::readEnv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        if ($endpoint === null) {
            $base = self::readEnv('OTEL_EXPORTER_OTLP_ENDPOINT');
            $endpoint = $base !== null ? rtrim($base, '/').'/v1/traces' : self::DEFAULT_ENDPOINT;
        }

        $serviceName = self::readEnv('OTEL_SERVICE_NAME') ?? self::DEFAULT_SERVICE_NAME;

        $timeoutRaw = self::readEnv('OTEL_EXPORTER_OTLP_TIMEOUT');
        $timeoutMs = $timeoutRaw !== null && is_numeric($timeoutRaw)
            ? max(50, (int) $timeoutRaw)
            : self::DEFAULT_TIMEOUT_MS;

        return new self(
            endpoint: $endpoint,
            serviceName: $serviceName,
            timeoutMs: $timeoutMs,
            headers: self::parseHeaders(self::readEnv('OTEL_EXPORTER_OTLP_HEADERS')),
            flushOnDestruct: true,
        );
    }

    public function __destruct()
    {
        if ($this->flushOnDestruct && $this->pendingSpans !== []) {
            $this->flush();
        }
    }

    /** @param array<string, scalar> $attributes */
    public function span(string $name, callable $operation, array $attributes = []): mixed
    {
        return $this->linkedSpan($name, $operation, $attributes, []);
    }

    /**
     * @template T
     *
     * @param  callable(): T         $operation
     * @param  array<string, scalar> $attributes
     * @param  list<string>          $links      W3C `traceparent` strings to link cleanly across async hops.
     * @return T
     */
    public function linkedSpan(string $name, callable $operation, array $attributes = [], array $links = []): mixed
    {
        $parent = $this->stack !== [] ? $this->stack[array_key_last($this->stack)] : null;
        $traceId = $parent['traceId']
            ?? $this->inboundContext['traceId']
            ?? bin2hex(random_bytes(16));
        $parentSpanId = $parent['spanId']
            ?? $this->inboundContext['spanId']
            ?? null;
        $spanId = bin2hex(random_bytes(8));

        $parsedLinks = [];
        foreach ($links as $candidate) {
            $parsed = self::parseTraceparent($candidate);
            if ($parsed !== null) {
                $parsedLinks[] = $parsed;
            }
        }

        $this->stack[] = ['traceId' => $traceId, 'spanId' => $spanId];
        $startTime = $this->unixNanoNow();
        $statusCode = self::STATUS_OK;
        $statusMessage = '';

        try {
            return $operation();
        } catch (Throwable $exception) {
            $statusCode = self::STATUS_ERROR;
            // Never export raw exception messages (e.g. QueryException may embed SQL bindings).
            $statusMessage = $exception::class;
            $attributes['vaulter.outcome'] = 'internal_error';
            $attributes['error.type'] = $exception::class;
            $code = $exception->getCode();
            if (is_int($code)) {
                $attributes['error.code'] = $code;
            } elseif (preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/', $code) === 1) {
                $attributes['error.code'] = $code;
            }

            throw $exception;
        } finally {
            $endTime = max($startTime, $this->unixNanoNow());
            array_pop($this->stack);

            $this->bufferSpan([
                'traceId' => $traceId,
                'spanId' => $spanId,
                'parentSpanId' => $parentSpanId,
                'name' => $name,
                'kind' => self::SPAN_KIND_INTERNAL,
                'startTimeUnixNano' => $startTime,
                'endTimeUnixNano' => $endTime,
                'attributes' => $attributes,
                'links' => $parsedLinks,
                'statusCode' => $statusCode,
                'statusMessage' => $statusMessage,
            ]);
        }
    }

    /**
     * Executes `$operation` under an inbound W3C `traceparent` parent when no active span is already open.
     *
     * @template T
     *
     * @param  callable(): T $operation
     * @return T
     */
    public function withTraceparent(?string $traceparent, callable $operation): mixed
    {
        $previous = $this->inboundContext;
        $this->inboundContext = self::parseTraceparent($traceparent);

        try {
            return $operation();
        } finally {
            $this->inboundContext = $previous;
        }
    }

    public function currentTraceparent(): ?string
    {
        if ($this->stack !== []) {
            $active = $this->stack[array_key_last($this->stack)];

            return self::formatTraceparent($active['traceId'], $active['spanId'], sampled: true);
        }

        if ($this->inboundContext !== null) {
            return self::formatTraceparent(
                $this->inboundContext['traceId'],
                $this->inboundContext['spanId'],
                ($this->inboundContext['flags'] & 1) === 1,
            );
        }

        return null;
    }

    public function bufferedCount(): int
    {
        return count($this->pendingSpans);
    }

    public function flush(): void
    {
        if ($this->pendingSpans === []) {
            return;
        }

        $batch = $this->pendingSpans;
        $this->pendingSpans = [];

        $payload = self::encodeExportTraceServiceRequest($batch, $this->serviceName);
        $headers = array_merge($this->headers, ['Content-Type' => 'application/x-protobuf']);

        try {
            if ($this->transport !== null) {
                ($this->transport)($this->endpoint, $payload, $headers, $this->timeoutMs);

                return;
            }

            $this->postProtobuf($payload, $headers);
        } catch (Throwable) {
            // Fail open: observability export failures must never disrupt requests or workers.
        }
    }

    /** @return ?ParsedTraceparent */
    public static function parseTraceparent(?string $traceparent): ?array
    {
        if ($traceparent === null) {
            return null;
        }

        $normalized = strtolower(trim($traceparent));
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $normalized, $matches) !== 1) {
            return null;
        }

        $traceId = $matches[1];
        $spanId = $matches[2];
        $flags = hexdec($matches[3]);

        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return ['traceId' => $traceId, 'spanId' => $spanId, 'flags' => (int) $flags];
    }

    public static function formatTraceparent(string $traceId, string $spanId, bool $sampled = true): string
    {
        return '00-'.strtolower($traceId).'-'.strtolower($spanId).'-'.($sampled ? '01' : '00');
    }

    /**
     * Encodes spans into binary `opentelemetry.proto.collector.trace.v1.ExportTraceServiceRequest` wire format.
     *
     * @param list<RecordedSpan> $spans
     */
    public static function encodeExportTraceServiceRequest(array $spans, string $serviceName): string
    {
        $encodedSpans = '';
        foreach ($spans as $span) {
            $encodedSpans .= self::lengthDelimitedField(2, self::encodeSpan($span));
        }

        $scope = self::lengthDelimitedField(1, 'vaulter.core');
        $scopeSpans = self::lengthDelimitedField(1, $scope).$encodedSpans;

        $resource = self::lengthDelimitedField(1, self::encodeKeyValue('service.name', $serviceName));
        $resourceSpans = self::lengthDelimitedField(1, $resource)
            .self::lengthDelimitedField(2, $scopeSpans);

        return self::lengthDelimitedField(1, $resourceSpans);
    }

    /** @param RecordedSpan $span */
    private static function encodeSpan(array $span): string
    {
        $out = self::lengthDelimitedField(1, (string) hex2bin($span['traceId']))
            .self::lengthDelimitedField(2, (string) hex2bin($span['spanId']));

        if ($span['parentSpanId'] !== null) {
            $out .= self::lengthDelimitedField(4, (string) hex2bin($span['parentSpanId']));
        }

        $out .= self::lengthDelimitedField(5, $span['name'])
            .self::varintField(6, $span['kind'])
            .self::fixed64Field(7, $span['startTimeUnixNano'])
            .self::fixed64Field(8, $span['endTimeUnixNano']);

        foreach ($span['attributes'] as $key => $value) {
            $out .= self::lengthDelimitedField(9, self::encodeKeyValue($key, $value));
        }

        foreach ($span['links'] as $link) {
            $encodedLink = self::lengthDelimitedField(1, (string) hex2bin($link['traceId']))
                .self::lengthDelimitedField(2, (string) hex2bin($link['spanId']))
                .self::fixed32Field(5, $link['flags']);
            $out .= self::lengthDelimitedField(11, $encodedLink);
        }

        $status = '';
        if ($span['statusMessage'] !== '') {
            $status .= self::lengthDelimitedField(2, $span['statusMessage']);
        }
        $status .= self::varintField(3, $span['statusCode'] === self::STATUS_ERROR ? self::STATUS_ERROR : ($span['statusCode'] === self::STATUS_OK ? self::STATUS_OK : self::STATUS_UNSET));

        return $out.self::lengthDelimitedField(15, $status);
    }

    private static function encodeKeyValue(string $key, int|float|string|bool $value): string
    {
        if (is_bool($value)) {
            $any = self::varintField(2, $value ? 1 : 0);
        } elseif (is_int($value)) {
            $any = self::varintField(3, $value);
        } elseif (is_float($value)) {
            $any = self::doubleField(4, $value);
        } else {
            $any = self::lengthDelimitedField(1, $value);
        }

        return self::lengthDelimitedField(1, $key)
            .self::lengthDelimitedField(2, $any);
    }

    private static function lengthDelimitedField(int $fieldNumber, string $bytes): string
    {
        return self::varint(($fieldNumber << 3) | 2)
            .self::varint(strlen($bytes))
            .$bytes;
    }

    private static function varintField(int $fieldNumber, int $value): string
    {
        return self::varint(($fieldNumber << 3) | 0).self::varint($value);
    }

    private static function fixed64Field(int $fieldNumber, int $value): string
    {
        return self::varint(($fieldNumber << 3) | 1).pack('P', $value);
    }

    private static function fixed32Field(int $fieldNumber, int $value): string
    {
        return self::varint(($fieldNumber << 3) | 5).pack('V', $value);
    }

    private static function doubleField(int $fieldNumber, float $value): string
    {
        return self::varint(($fieldNumber << 3) | 1).pack('e', $value);
    }

    private static function varint(int $value): string
    {
        $bytes = '';
        $remaining = $value;
        while (($remaining & ~0x7F) !== 0) {
            $bytes .= chr(($remaining & 0x7F) | 0x80);
            $remaining = ($remaining >> 7) & (PHP_INT_MAX >> 6);
        }

        return $bytes.chr($remaining);
    }

    /** @param RecordedSpan $span */
    private function bufferSpan(array $span): void
    {
        if ($this->maxBufferedSpans <= 0) {
            return;
        }
        if (count($this->pendingSpans) >= $this->maxBufferedSpans) {
            array_shift($this->pendingSpans);
        }
        $this->pendingSpans[] = $span;
    }

    /** @param array<string, string> $headers */
    private function postProtobuf(string $payload, array $headers): void
    {
        $parsed = self::parseEndpoint($this->endpoint);
        if ($parsed === null) {
            return;
        }

        $budgetMs = max(10, min($this->timeoutMs, 250));
        $deadlineNs = hrtime(true) + ($budgetMs * 1_000_000);
        $remote = 'tcp://'.$parsed['socketHost'].':'.$parsed['port'];

        $socket = null;
        try {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $budgetMs / 1000,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            );
            if (!is_resource($socket)) {
                return;
            }

            stream_set_blocking($socket, false);

            if (!self::waitForStream($socket, waitRead: false, waitWrite: true, deadlineNs: $deadlineNs)) {
                return;
            }

            if ($parsed['scheme'] === 'https' && !self::enableTlsNonBlocking($socket, $deadlineNs)) {
                return;
            }

            $request = self::buildHttpPostRequest($parsed['path'], $parsed['hostHeader'], $headers, $payload);
            $total = strlen($request);
            $offset = 0;

            while ($offset < $total) {
                $chunk = @fwrite($socket, substr($request, $offset));
                if ($chunk === false) {
                    return;
                }
                if ($chunk === 0) {
                    if (!self::waitForStream($socket, waitRead: false, waitWrite: true, deadlineNs: $deadlineNs)) {
                        return;
                    }

                    continue;
                }
                $offset += $chunk;
            }

            // Do not block PHP-FPM workers waiting for a stalled collector HTTP response.
            @fflush($socket);
        } catch (Throwable) {
            // Tracing export is fail-open on the hot path and shutdown hook.
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /** @return ?array{scheme: 'http'|'https', socketHost: string, hostHeader: string, port: int, path: string} */
    private static function parseEndpoint(string $endpoint): ?array
    {
        if (preg_match('~\A(https?)://(\[[a-f0-9:.]+\]|[^:/?#\s]+)(?::([0-9]{1,5}))?(/[^#]*)?(?:#.*)?\z~i', trim($endpoint), $matches) !== 1) {
            return null;
        }

        $scheme = strtolower($matches[1]) === 'https' ? 'https' : 'http';
        $rawHost = $matches[2];
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : $defaultPort;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $path = $matches[4] ?? '/';
        $hostHeader = $port === $defaultPort ? $rawHost : $rawHost.':'.$port;

        return [
            'scheme' => $scheme,
            'socketHost' => $rawHost,
            'hostHeader' => $hostHeader,
            'port' => $port,
            'path' => $path,
        ];
    }

    /** @param array<string, string> $headers */
    private static function buildHttpPostRequest(string $path, string $hostHeader, array $headers, string $payload): string
    {
        $lines = [
            'POST '.$path.' HTTP/1.1',
            'Host: '.$hostHeader,
            'Connection: close',
            'Content-Length: '.strlen($payload),
        ];

        foreach ($headers as $name => $value) {
            $cleanName = str_replace(["\r", "\n"], '', $name);
            $cleanValue = str_replace(["\r", "\n"], '', $value);
            if ($cleanName !== '') {
                $lines[] = $cleanName.': '.$cleanValue;
            }
        }

        return implode("\r\n", $lines)."\r\n\r\n".$payload;
    }

    /** @param resource $socket */
    private static function enableTlsNonBlocking($socket, int $deadlineNs): bool
    {
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        while (true) {
            $result = @stream_socket_enable_crypto($socket, true, $methods);
            if ($result === true) {
                return true;
            }
            if ($result === false) {
                return false;
            }
            if (!self::waitForStream($socket, waitRead: true, waitWrite: true, deadlineNs: $deadlineNs)) {
                return false;
            }
        }
    }

    /** @param resource $socket */
    private static function waitForStream($socket, bool $waitRead, bool $waitWrite, int $deadlineNs): bool
    {
        $remainingNs = $deadlineNs - hrtime(true);
        if ($remainingNs <= 0) {
            return false;
        }

        $remainingUs = intdiv($remainingNs, 1_000);
        $seconds = intdiv($remainingUs, 1_000_000);
        $microseconds = $remainingUs % 1_000_000;

        $read = $waitRead ? [$socket] : null;
        $write = $waitWrite ? [$socket] : null;
        $except = null;

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        return is_int($ready) && $ready > 0;
    }

    private function unixNanoNow(): int
    {
        if ($this->baseWallNano === null || $this->baseHrNano === null) {
            [$usec, $sec] = explode(' ', microtime());
            $this->baseWallNano = ((int) $sec * 1_000_000_000) + (int) round((float) $usec * 1_000_000_000);
            $this->baseHrNano = hrtime(true);
        }

        return $this->baseWallNano + max(0, hrtime(true) - $this->baseHrNano);
    }

    private static function readEnv(string $name): ?string
    {
        $raw = $_SERVER[$name] ?? getenv($name);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /** @return array<string, string> */
    private static function parseHeaders(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $headers = [];
        foreach (explode(',', $raw) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $pos));
            $val = trim(substr($pair, $pos + 1));
            if ($key !== '' && $val !== '') {
                $headers[$key] = $val;
            }
        }

        return $headers;
    }
}
