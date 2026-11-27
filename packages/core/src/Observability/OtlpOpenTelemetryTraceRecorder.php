<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Closure;
use Illuminate\Support\Env;
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

    private const int DNS_BUDGET_MS = 25;

    private const int DNS_MAX_CACHE_TTL_SECONDS = 60;

    private const int DNS_MAX_CACHE_ENTRIES = 64;

    private const int DNS_TYPE_A = 1;

    private const int DNS_TYPE_AAAA = 28;

    private const int SPAN_KIND_INTERNAL = 1;

    private const int STATUS_UNSET = 0;

    private const int STATUS_OK = 1;

    private const int STATUS_ERROR = 2;

    /** @var array<string, array{ips: list<string>, expiresAtNs: int}> */
    private static array $dnsCache = [];

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
     * @param ?list<string>                                              $dnsServers
     * @param ?Closure(string, int): (string|list<string>|null)          $dnsResolver
     */
    public function __construct(
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly string $serviceName = self::DEFAULT_SERVICE_NAME,
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        private readonly array $headers = [],
        private readonly int $maxBufferedSpans = self::DEFAULT_MAX_BUFFERED_SPANS,
        private readonly ?Closure $transport = null,
        private readonly bool $flushOnDestruct = false,
        private readonly ?array $dnsServers = null,
        private readonly ?Closure $dnsResolver = null,
        private readonly ?string $certificateFile = null,
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
            ? max(10, (int) $timeoutRaw)
            : self::DEFAULT_TIMEOUT_MS;

        $certificateFile = self::readEnv('OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE')
            ?? self::readEnv('OTEL_EXPORTER_OTLP_CERTIFICATE');

        return new self(
            endpoint: $endpoint,
            serviceName: $serviceName,
            timeoutMs: $timeoutMs,
            headers: self::parseHeaders(self::readEnv('OTEL_EXPORTER_OTLP_HEADERS')),
            flushOnDestruct: true,
            certificateFile: $certificateFile,
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
            if (is_int($code) || preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/', $code) === 1) {
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
        $active = $this->stack[array_key_last($this->stack)] ?? null;
        $context = $active ?? $this->inboundContext;
        if ($context === null) {
            return null;
        }

        return self::formatTraceparent($context['traceId'], $context['spanId'], (($context['flags'] ?? 1) & 1) === 1);
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
                .self::fixed32Field(6, $link['flags']);
            $out .= self::lengthDelimitedField(13, $encodedLink);
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
        $any = match (true) {
            is_bool($value) => self::varintField(2, $value ? 1 : 0),
            is_int($value) => self::varintField(3, $value),
            is_float($value) => self::doubleField(4, $value),
            default => self::lengthDelimitedField(1, $value),
        };

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
        $resolvedIps = $this->resolveSocketHosts($parsed['socketHost'], $deadlineNs);
        if ($resolvedIps === []) {
            return;
        }

        $contextOptions = [];
        if ($parsed['scheme'] === 'https') {
            $ssl = [];
            if (!$parsed['isIpLiteral']) {
                $ssl['peer_name'] = $parsed['rawHost'];
                $ssl['SNI_enabled'] = true;
            }
            if ($this->certificateFile !== null && $this->certificateFile !== '') {
                $ssl['cafile'] = $this->certificateFile;
            }
            if ($ssl !== []) {
                $contextOptions['ssl'] = $ssl;
            }
        }
        $request = self::buildHttpPostRequest($parsed['path'], $parsed['hostHeader'], $headers, $payload);

        $candidateCount = count($resolvedIps);
        foreach ($resolvedIps as $index => $resolvedIp) {
            $nowNs = hrtime(true);
            $remainingNs = $deadlineNs - $nowNs;
            if ($remainingNs <= 0) {
                return;
            }

            $remainingCandidates = $candidateCount - $index;
            $attemptBudgetNs = $remainingCandidates > 1
                ? max(1, intdiv($remainingNs, $remainingCandidates))
                : $remainingNs;
            $attemptDeadlineNs = min($deadlineNs, $nowNs + $attemptBudgetNs);

            if ($this->tryPostProtobufToIp($parsed, $resolvedIp, $contextOptions, $request, $attemptDeadlineNs, $deadlineNs)) {
                return;
            }
        }
    }

    /**
     * @param array{scheme: 'http'|'https', socketHost: string, rawHost: string, isIpLiteral: bool, hostHeader: string, port: int, path: string} $parsed
     * @param array<string, array<string, mixed>>                                                                                                $contextOptions
     */
    private function tryPostProtobufToIp(
        array $parsed,
        string $resolvedIp,
        array $contextOptions,
        string $request,
        int $attemptDeadlineNs,
        int $deadlineNs,
    ): bool {
        $attemptRemainingNs = $attemptDeadlineNs - hrtime(true);
        if ($attemptRemainingNs <= 0) {
            return false;
        }

        $remoteHost = str_contains($resolvedIp, ':') ? '['.$resolvedIp.']' : $resolvedIp;
        $remote = 'tcp://'.$remoteHost.':'.$parsed['port'];
        $context = stream_context_create($contextOptions);

        $socket = null;
        try {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                max(0.001, $attemptRemainingNs / 1_000_000_000),
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!is_resource($socket)) {
                return false;
            }

            stream_set_blocking($socket, false);

            if (!self::waitForStream($socket, waitRead: false, waitWrite: true, deadlineNs: $attemptDeadlineNs)) {
                return false;
            }

            if (@stream_socket_get_name($socket, true) === false) {
                return false;
            }

            if ($parsed['scheme'] === 'https' && !self::enableTlsNonBlocking($socket, $attemptDeadlineNs)) {
                return false;
            }

            $total = strlen($request);
            $offset = 0;

            while ($offset < $total) {
                if (hrtime(true) >= $deadlineNs) {
                    return $offset > 0;
                }
                $chunk = @fwrite($socket, substr($request, $offset));
                if ($chunk === false) {
                    return $offset > 0;
                }
                if ($chunk === 0) {
                    $writeDeadlineNs = $offset === 0 ? $attemptDeadlineNs : $deadlineNs;
                    if (!self::waitForStream($socket, waitRead: false, waitWrite: true, deadlineNs: $writeDeadlineNs)) {
                        return $offset > 0;
                    }

                    continue;
                }
                $offset += $chunk;
            }

            // Do not block PHP-FPM workers waiting for a stalled collector HTTP response.
            @fflush($socket);

            return true;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /** @return ?array{scheme: 'http'|'https', socketHost: string, rawHost: string, isIpLiteral: bool, hostHeader: string, port: int, path: string} */
    private static function parseEndpoint(string $endpoint): ?array
    {
        if (preg_match('~\A(https?)://(\[[a-f0-9:.]+\]|[^:/?#\s]+)(?::([0-9]{1,5}))?(/[^#]*)?(?:#.*)?\z~i', trim($endpoint), $matches) !== 1) {
            return null;
        }

        $scheme = strtolower($matches[1]) === 'https' ? 'https' : 'http';
        $socketHost = $matches[2];
        $rawHost = trim($socketHost, '[]');
        $isIpLiteral = filter_var($rawHost, FILTER_VALIDATE_IP) !== false;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : $defaultPort;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $path = $matches[4] ?? '/';
        $hostHeader = $port === $defaultPort ? $socketHost : $socketHost.':'.$port;

        return [
            'scheme' => $scheme,
            'socketHost' => $socketHost,
            'rawHost' => $rawHost,
            'isIpLiteral' => $isIpLiteral,
            'hostHeader' => $hostHeader,
            'port' => $port,
            'path' => $path,
        ];
    }

    /** @return list<string> */
    private function resolveSocketHosts(string $socketHost, int $deadlineNs): array
    {
        $host = trim($socketHost, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $dnsDeadlineNs = min($deadlineNs, hrtime(true) + (self::DNS_BUDGET_MS * 1_000_000));
        if (hrtime(true) >= $dnsDeadlineNs) {
            return [];
        }

        if ($this->dnsResolver !== null) {
            $resolved = ($this->dnsResolver)($host, $dnsDeadlineNs);
            if (hrtime(true) >= $deadlineNs) {
                return [];
            }

            return self::normalizeIpList($resolved);
        }

        $cacheKey = strtolower($host).'|'.($this->dnsServers !== null ? implode(',', $this->dnsServers) : '');
        $cached = self::$dnsCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAtNs'] > hrtime(true)) {
            return $cached['ips'];
        }
        unset(self::$dnsCache[$cacheKey]);

        if ($this->dnsServers === null) {
            $hostsIps = self::lookupHostsFile($host);
            if ($hostsIps !== []) {
                self::rememberResolvedIps($cacheKey, $hostsIps, 5);

                return $hostsIps;
            }
        }

        if ($this->dnsServers !== null) {
            $nameservers = self::parseExplicitDnsServers($this->dnsServers);
            $search = [];
            $ndots = 1;
        } else {
            ['nameservers' => $nameservers, 'search' => $search, 'ndots' => $ndots] = self::readResolvConf();
        }

        if ($nameservers === []) {
            return [];
        }

        foreach (self::candidateHostnames($host, $search, $ndots) as $candidate) {
            if (hrtime(true) >= $dnsDeadlineNs) {
                return [];
            }

            $result = self::queryUdpDns($candidate, $nameservers, $dnsDeadlineNs, $deadlineNs);
            if ($result !== null && $result['ips'] !== []) {
                if ($result['ttl'] > 0) {
                    self::rememberResolvedIps($cacheKey, $result['ips'], $result['ttl']);
                }

                return $result['ips'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function normalizeIpList(mixed $resolved): array
    {
        $candidates = is_array($resolved) ? $resolved : (is_string($resolved) ? [$resolved] : []);
        $ips = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $candidate;
            }
        }

        return array_values(array_unique($ips));
    }

    /** @param list<string> $ips */
    private static function rememberResolvedIps(string $cacheKey, array $ips, int $ttlSeconds): void
    {
        $clampedTtl = min(self::DNS_MAX_CACHE_TTL_SECONDS, max(1, $ttlSeconds));
        if (count(self::$dnsCache) >= self::DNS_MAX_CACHE_ENTRIES) {
            array_shift(self::$dnsCache);
        }
        self::$dnsCache[$cacheKey] = [
            'ips' => $ips,
            'expiresAtNs' => hrtime(true) + ($clampedTtl * 1_000_000_000),
        ];
    }

    /** @return list<string> */
    private static function lookupHostsFile(string $host): array
    {
        $contents = @file_get_contents('/etc/hosts');
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $target = strtolower($host);
        $ipv4Matches = [];
        $ipv6Matches = [];
        foreach (explode("\n", $contents) as $line) {
            $hashPos = strpos($line, '#');
            if ($hashPos !== false) {
                $line = substr($line, 0, $hashPos);
            }
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $trimmed);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $ip = $parts[0];
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            for ($index = 1, $count = count($parts); $index < $count; $index++) {
                if (strtolower($parts[$index]) !== $target) {
                    continue;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $ipv4Matches[] = $ip;
                } else {
                    $ipv6Matches[] = $ip;
                }
            }
        }

        return array_values(array_unique([...$ipv4Matches, ...$ipv6Matches]));
    }

    /** @return array{nameservers: list<array{ip: string, port: int}>, search: list<string>, ndots: int} */
    private static function readResolvConf(): array
    {
        $nameservers = [];
        $search = [];
        $ndots = 1;

        $contents = @file_get_contents('/etc/resolv.conf');
        if (!is_string($contents) || $contents === '') {
            return ['nameservers' => [], 'search' => [], 'ndots' => 1];
        }

        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim(preg_replace('/[#;].*$/', '', $line) ?? '');
            if ($trimmed === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $trimmed);
            if (!is_array($parts)) {
                continue;
            }
            $directive = strtolower($parts[0]);
            if ($directive === 'nameserver' && isset($parts[1]) && filter_var($parts[1], FILTER_VALIDATE_IP) !== false) {
                $nameservers[] = ['ip' => $parts[1], 'port' => 53];
            } elseif (($directive === 'search' || $directive === 'domain') && count($parts) > 1) {
                $search = [];
                for ($index = 1, $count = count($parts); $index < $count; $index++) {
                    $suffix = trim($parts[$index], '.');
                    if ($suffix !== '') {
                        $search[] = $suffix;
                    }
                }
            } elseif ($directive === 'options') {
                for ($index = 1, $count = count($parts); $index < $count; $index++) {
                    if (preg_match('/^ndots:(\d+)$/i', $parts[$index], $match) === 1) {
                        $ndots = max(0, min(15, (int) $match[1]));
                    }
                }
            }
        }

        return ['nameservers' => $nameservers, 'search' => $search, 'ndots' => $ndots];
    }

    /**
     * @param  list<string>                       $rawServers
     * @return list<array{ip: string, port: int}>
     */
    private static function parseExplicitDnsServers(array $rawServers): array
    {
        $parsed = [];
        foreach ($rawServers as $server) {
            $trimmed = trim($server);
            if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/i', $trimmed, $match) === 1) {
                $ip = $match[1];
                $port = isset($match[2]) ? (int) $match[2] : 53;
            } elseif (substr_count($trimmed, ':') === 1) {
                [$ip, $portRaw] = explode(':', $trimmed, 2);
                $port = ctype_digit($portRaw) ? (int) $portRaw : 0;
            } else {
                $ip = $trimmed;
                $port = 53;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && $port >= 1 && $port <= 65535) {
                $parsed[] = ['ip' => $ip, 'port' => $port];
            }
        }

        return $parsed;
    }

    /**
     * @param  list<string> $search
     * @return list<string>
     */
    private static function candidateHostnames(string $host, array $search, int $ndots): array
    {
        $trimmed = rtrim($host, '.');
        if ($trimmed === '') {
            return [];
        }
        if (str_ends_with($host, '.') || $search === []) {
            return [$trimmed];
        }

        $expanded = [];
        foreach ($search as $suffix) {
            $expanded[] = $trimmed.'.'.$suffix;
        }

        $candidates = substr_count($trimmed, '.') >= $ndots
            ? [$trimmed, ...$expanded]
            : [...$expanded, $trimmed];

        return array_values(array_unique($candidates));
    }

    /**
     * @param  list<array{ip: string, port: int}>  $nameservers
     * @return ?array{ips: list<string>, ttl: int}
     */
    private static function queryUdpDns(string $hostname, array $nameservers, int $dnsDeadlineNs, int $overallDeadlineNs): ?array
    {
        $txIdA = random_int(1, 0xFFFF);
        $txIdAAAA = $txIdA === 0xFFFF ? 1 : $txIdA + 1;
        $packetA = self::encodeDnsQuery($hostname, $txIdA, self::DNS_TYPE_A);
        $packetAAAA = self::encodeDnsQuery($hostname, $txIdAAAA, self::DNS_TYPE_AAAA);
        if ($packetA === null || $packetAAAA === null) {
            return null;
        }

        /** @var array<int, array{socket: resource, txId: int, qtype: int, serverIndex: int}> $queries */
        $queries = [];
        /** @var array<int, int> $serverPending */
        $serverPending = [];
        /** @var array<int, bool> $serverHasIp */
        $serverHasIp = [];
        /** @var list<string> $ipv4 */
        $ipv4 = [];
        /** @var list<string> $ipv6 */
        $ipv6 = [];
        $minTtl = null;
        $firstIpNs = null;

        try {
            foreach (array_slice($nameservers, 0, 2) as $serverIndex => $server) {
                $host = str_contains($server['ip'], ':') ? '['.$server['ip'].']' : $server['ip'];
                $target = 'udp://'.$host.':'.$server['port'];

                foreach ([
                    ['qtype' => self::DNS_TYPE_A, 'txId' => $txIdA, 'packet' => $packetA],
                    ['qtype' => self::DNS_TYPE_AAAA, 'txId' => $txIdAAAA, 'packet' => $packetAAAA],
                ] as $spec) {
                    if (hrtime(true) >= $dnsDeadlineNs) {
                        break 2;
                    }

                    $errno = 0;
                    $errstr = '';
                    $socket = @stream_socket_client(
                        $target,
                        $errno,
                        $errstr,
                        0.01,
                        STREAM_CLIENT_CONNECT,
                    );
                    if (!is_resource($socket)) {
                        continue;
                    }

                    stream_set_blocking($socket, false);
                    $written = @fwrite($socket, $spec['packet']);
                    if ($written !== strlen($spec['packet'])) {
                        @fclose($socket);

                        continue;
                    }

                    $queries[(int) $socket] = [
                        'socket' => $socket,
                        'txId' => $spec['txId'],
                        'qtype' => $spec['qtype'],
                        'serverIndex' => $serverIndex,
                    ];
                    $serverPending[$serverIndex] = ($serverPending[$serverIndex] ?? 0) + 1;
                }
            }

            while ($queries !== []) {
                $effectiveDeadlineNs = $dnsDeadlineNs;
                if ($firstIpNs !== null) {
                    $deltaNs = $overallDeadlineNs - $firstIpNs;
                    $halfRemainingNs = is_int($deltaNs) && $deltaNs > 0 ? intdiv($deltaNs, 2) : 0;
                    $effectiveDeadlineNs = min($dnsDeadlineNs, $firstIpNs + max(1_000_000, $halfRemainingNs));
                }

                $remainingNs = $effectiveDeadlineNs - hrtime(true);
                if ($remainingNs <= 0) {
                    break;
                }

                $remainingUs = intdiv($remainingNs, 1_000);
                $seconds = intdiv($remainingUs, 1_000_000);
                $microseconds = $remainingUs % 1_000_000;
                $read = [];
                foreach ($queries as $entry) {
                    $read[] = $entry['socket'];
                }
                $write = null;
                $except = null;

                $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
                if (!is_int($ready) || $ready <= 0) {
                    break;
                }

                foreach ($read as $socket) {
                    $key = (int) $socket;
                    $meta = $queries[$key] ?? null;
                    if ($meta === null) {
                        continue;
                    }

                    $response = @fread($socket, 4096);
                    if (!is_string($response) || $response === '') {
                        unset($queries[$key]);
                        @fclose($socket);
                        $serverPending[$meta['serverIndex']] = max(0, ($serverPending[$meta['serverIndex']] ?? 1) - 1);
                        if ($serverPending[$meta['serverIndex']] === 0 && ($serverHasIp[$meta['serverIndex']] ?? false)) {
                            break 2;
                        }

                        continue;
                    }

                    $decoded = self::decodeDnsResponse($response, $meta['txId']);
                    if ($decoded === null) {
                        continue;
                    }

                    unset($queries[$key]);
                    @fclose($socket);
                    $serverPending[$meta['serverIndex']] = max(0, ($serverPending[$meta['serverIndex']] ?? 1) - 1);

                    if ($decoded['ips'] !== []) {
                        $serverHasIp[$meta['serverIndex']] = true;
                        $firstIpNs ??= hrtime(true);
                        $minTtl = $minTtl === null ? $decoded['ttl'] : min($minTtl, $decoded['ttl']);
                        foreach ($decoded['ips'] as $ip) {
                            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                                $ipv4[] = $ip;
                            } else {
                                $ipv6[] = $ip;
                            }
                        }
                    }

                    if ($serverPending[$meta['serverIndex']] === 0 && ($serverHasIp[$meta['serverIndex']] ?? false)) {
                        break 2;
                    }
                }
            }

            $ips = array_values(array_unique([...$ipv4, ...$ipv6]));

            return $ips !== [] ? ['ips' => $ips, 'ttl' => $minTtl ?? 0] : null;
        } finally {
            foreach ($queries as $entry) {
                if (is_resource($entry['socket'])) {
                    @fclose($entry['socket']);
                }
            }
        }
    }

    private static function encodeDnsQuery(string $hostname, int $txId, int $qtype = self::DNS_TYPE_A): ?string
    {
        if (strlen($hostname) > 253) {
            return null;
        }

        $labels = explode('.', $hostname);
        $qname = '';
        foreach ($labels as $label) {
            $len = strlen($label);
            if ($len < 1 || $len > 63) {
                return null;
            }
            $qname .= chr($len).$label;
        }
        $qname .= "\x00";

        return pack('nnnnnn', $txId, 0x0100, 1, 0, 0, 0).$qname.pack('nn', $qtype, 1);
    }

    /** @return ?array{ips: list<string>, ttl: int} */
    private static function decodeDnsResponse(string $packet, int $expectedTxId): ?array
    {
        $length = strlen($packet);
        if ($length < 12) {
            return null;
        }

        /** @var array<string, int>|false $header */
        $header = unpack('nid/nflags/nqdcount/nancount', substr($packet, 0, 8));
        if (!is_array($header) || $header['id'] !== $expectedTxId) {
            return null;
        }
        if (($header['flags'] & 0x8000) === 0) {
            return null;
        }

        $rcode = $header['flags'] & 0x000F;
        if ($rcode !== 0 || $header['ancount'] === 0) {
            return ['ips' => [], 'ttl' => 0];
        }

        $offset = 12;
        for ($i = 0; $i < $header['qdcount']; $i++) {
            $next = self::skipDnsName($packet, $offset, $length);
            if ($next === null || $next + 4 > $length) {
                return null;
            }
            $offset = $next + 4;
        }

        $ips = [];
        $minTtl = null;
        for ($i = 0; $i < $header['ancount']; $i++) {
            $next = self::skipDnsName($packet, $offset, $length);
            if ($next === null || $next + 10 > $length) {
                return null;
            }
            /** @var array<string, int>|false $rr */
            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($packet, $next, 10));
            if (!is_array($rr)) {
                return null;
            }
            $rdataOffset = $next + 10;
            $rdlength = $rr['rdlength'];
            if ($rdataOffset + $rdlength > $length) {
                return null;
            }

            $ip = null;
            if ($rr['class'] === 1 && $rr['type'] === self::DNS_TYPE_A && $rdlength === 4) {
                $decoded = @inet_ntop(substr($packet, $rdataOffset, 4));
                if (is_string($decoded)) {
                    $ip = $decoded;
                }
            } elseif ($rr['class'] === 1 && $rr['type'] === self::DNS_TYPE_AAAA && $rdlength === 16) {
                $decoded = @inet_ntop(substr($packet, $rdataOffset, 16));
                if (is_string($decoded)) {
                    $ip = $decoded;
                }
            }

            if ($ip !== null) {
                $ips[] = $ip;
                $ttl = max(0, $rr['ttl']);
                $minTtl = $minTtl === null ? $ttl : min($minTtl, $ttl);
            }

            $offset = $rdataOffset + $rdlength;
        }

        return ['ips' => array_values(array_unique($ips)), 'ttl' => $minTtl ?? 0];
    }

    private static function skipDnsName(string $packet, int $offset, int $length): ?int
    {
        $steps = 0;
        while ($offset < $length && $steps < 128) {
            $labelLen = ord($packet[$offset]);
            if ($labelLen === 0) {
                return $offset + 1;
            }
            if (($labelLen & 0xC0) === 0xC0) {
                return $offset + 2 <= $length ? $offset + 2 : null;
            }
            if (($labelLen & 0xC0) !== 0) {
                return null;
            }
            $offset += 1 + $labelLen;
            $steps++;
        }

        return null;
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
            if (is_bool($result)) {
                return $result;
            }
            if (!self::waitForStream($socket, waitRead: true, waitWrite: false, deadlineNs: $deadlineNs)) {
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
        $raw = Env::get($name);

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
