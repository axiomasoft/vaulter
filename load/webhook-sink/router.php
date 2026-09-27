<?php

declare(strict_types=1);

/**
 * Controlled webhook sink for the load stand (P6.2).
 *
 * Profiles: 2xx, 500, delay (slow-under-timeout), hang (over-timeout).
 * The ledger stores event_id, profile, status, start, end and ordinal.
 * Signature headers and the request body are discarded and never written.
 * hang sleeps only until LOAD_SINK_HANG_SECONDS, capped at 30s.
 */
function sink_allowed_hosts(): array
{
    $raw = getenv('LOAD_SINK_HOSTS');
    $value = is_string($raw) && $raw !== '' ? $raw : 'webhook-sink,127.0.0.1,localhost';

    return array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', $value),
    )));
}

function sink_port(): int
{
    $raw = getenv('LOAD_SINK_PORT');
    $port = is_numeric($raw) ? (int) $raw : 8090;

    return max(1, min($port, 65535));
}

function sink_hang_seconds(): int
{
    $raw = getenv('LOAD_SINK_HANG_SECONDS');
    $seconds = is_numeric($raw) ? (int) $raw : 8;

    return max(0, min($seconds, 30));
}

function sink_delay_ms(): int
{
    $raw = getenv('LOAD_SINK_DELAY_MS');
    $ms = is_numeric($raw) ? (int) $raw : 50;

    return max(0, min($ms, 4000));
}

function sink_client_allowed(string $remote): bool
{
    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $packed = inet_pton($remote);
    if ($packed === false) {
        return false;
    }

    $octets = array_values(unpack('C4', $packed));
    $a = $octets[0];
    $b = $octets[1];

    if ($a === 127 || $a === 10) {
        return true;
    }

    if ($a === 172 && $b >= 16 && $b <= 31) {
        return true;
    }

    return $a === 192 && $b === 168;
}

function sink_profile(string $path): ?string
{
    $trimmed = trim($path, '/');
    $head = strtolower(explode('/', $trimmed, 2)[0] ?? '');

    return match ($head) {
        '2xx' => '2xx',
        '500' => '500',
        'delay', 'slow-under-timeout' => 'delay',
        'hang', 'over-timeout' => 'hang',
        default => null,
    };
}

function sink_event_id(string $path, array $query): string
{
    $fromQuery = $query['event_id'] ?? '';
    if (is_string($fromQuery) && $fromQuery !== '') {
        return $fromQuery;
    }

    $parts = explode('/', trim($path, '/'));

    return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : '';
}

function sink_status(string $profile): int
{
    return match ($profile) {
        '500' => 500,
        'hang' => 504,
        default => 204,
    };
}

function sink_request_delay_ms(array $query, string $profile): int
{
    if ($profile !== 'delay') {
        return 0;
    }

    $override = $query['delay_ms'] ?? null;
    if (is_string($override) && is_numeric($override)) {
        return max(0, min((int) $override, 4000));
    }

    return sink_delay_ms();
}

/**
 * Path and query win. A JSON object may contribute only its event_id string.
 * The rest of the body is discarded and never written.
 */
function sink_event_id_from_request(string $path, array $query): string
{
    $explicit = sink_event_id($path, $query);
    $stream = fopen('php://input', 'rb');
    $body = $stream === false ? '' : (string) stream_get_contents($stream);
    if (is_resource($stream)) {
        fclose($stream);
    }

    if ($explicit !== '') {
        return $explicit;
    }

    $decoded = json_decode($body, true);
    $candidate = is_array($decoded) ? ($decoded['event_id'] ?? null) : null;

    return is_string($candidate) ? $candidate : '';
}

function sink_apply_profile(string $profile, int $delayMs): void
{
    if ($profile === 'delay') {
        usleep($delayMs * 1000);

        return;
    }

    if ($profile === 'hang') {
        $seconds = sink_hang_seconds();
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}

function sink_wall(float $instant): string
{
    $seconds = (int) $instant;
    $millis = (int) round(($instant - $seconds) * 1000);
    if ($millis === 1000) {
        $seconds++;
        $millis = 0;
    }

    return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03dZ', $millis);
}

/**
 * @param array<string, mixed> $query
 */
function sink_record(string $ledger, string $profile, int $status, string $eventId, array $query, float $started): void
{
    $directory = dirname($ledger);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $handle = fopen($ledger, 'a+b');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        // Read only the last row: rescanning a growing ledger distorted large drains.
        $size = fstat($handle)['size'];
        $ordinal = 1;
        if ($size > 0) {
            fseek($handle, max(0, $size - 4096));
            $tail = trim((string) stream_get_contents($handle));
            $lines = explode("\n", $tail);
            $last = json_decode(end($lines), true);
            if (!is_array($last) || !is_int($last['ordinal'] ?? null)) {
                throw new JsonException('Invalid sink ledger tail.');
            }
            $ordinal = $last['ordinal'] + 1;
        }

        $row = [
            'run_id' => is_string($query['run_id'] ?? null) ? $query['run_id'] : '',
            'cell_id' => is_string($query['cell_id'] ?? null) ? $query['cell_id'] : '',
            'event_id' => $eventId,
            'slot' => is_string($query['slot'] ?? null) ? $query['slot'] : '',
            'profile' => $profile,
            'status' => $status,
            'start' => sink_wall($started),
            'end' => sink_wall(microtime(true)),
            'ordinal' => $ordinal,
        ];

        fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    } catch (JsonException) {
        return;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function sink_dispatch(): void
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

    if ($method === 'GET' && ($path === '/health' || $path === '/')) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo "ok\n";

        return;
    }

    if (!sink_client_allowed($remote)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "forbidden\n";

        return;
    }

    $profile = sink_profile($path);
    if ($method !== 'POST' || $profile === null) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "unknown profile\n";

        return;
    }

    $query = [];
    parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?: ''), $query);
    $eventId = sink_event_id_from_request($path, $query);
    $started = microtime(true);
    sink_apply_profile($profile, sink_request_delay_ms($query, $profile));
    $status = sink_status($profile);
    $ledger = getenv('LOAD_SINK_LEDGER');
    $ledgerPath = is_string($ledger) && $ledger !== '' ? $ledger : sys_get_temp_dir().'/vaulter-sink-ledger.jsonl';
    sink_record($ledgerPath, $profile, $status, $eventId, $query, $started);

    http_response_code($status);
    header('Content-Type: text/plain');
    echo "{$profile}\n";
}

if (PHP_SAPI === 'cli-server') {
    sink_dispatch();
}
