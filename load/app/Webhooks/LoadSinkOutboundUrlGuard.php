<?php

declare(strict_types=1);

namespace Vaulter\Load\Webhooks;

use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException;
use Vaulter\Webhooks\ValueObjects\PinnedOutboundEndpoint;

/**
 * Load-stand outbound guard. Delivery may dial only the controlled sink.
 */
final readonly class LoadSinkOutboundUrlGuard implements OutboundUrlGuard
{
    public function isAllowed(string $url): void
    {
        $this->pin($url);
    }

    public function pin(string $url): PinnedOutboundEndpoint
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw BlockedOutboundUrlException::malformed($url);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw BlockedOutboundUrlException::malformed($url);
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== 'http') {
            throw BlockedOutboundUrlException::scheme($scheme);
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : 80;
        $allowedPort = self::allowedPort();

        if (!in_array($host, self::allowedHosts(), true) || $port !== $allowedPort) {
            throw new BlockedOutboundUrlException(
                sprintf(
                    '[Vaulter] Webhook delivery refused: [%s] is outside the load sink. Why: the stand may POST only to the controlled sink. Fix: use http://%s:%d/. Docs: load-sink',
                    $url,
                    self::allowedHosts()[0] ?? 'webhook-sink',
                    $allowedPort,
                ),
            );
        }

        $ip = self::pinAddress($host);
        if (!self::isStandAddress($ip)) {
            throw BlockedOutboundUrlException::blockedAddress($host, $ip);
        }

        return new PinnedOutboundEndpoint(scheme: 'http', host: $host, ip: $ip, port: $port);
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $raw = getenv('LOAD_SINK_HOSTS');
        $value = is_string($raw) && $raw !== '' ? $raw : 'webhook-sink,127.0.0.1,localhost';

        return array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $value),
        )));
    }

    public static function allowedPort(): int
    {
        $raw = getenv('LOAD_SINK_PORT');
        $port = is_numeric($raw) ? (int) $raw : 8090;

        return max(1, min($port, 65535));
    }

    private static function pinAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if ($host === 'localhost') {
            return '127.0.0.1';
        }

        $records = @gethostbynamel($host);
        if (!is_array($records) || $records === []) {
            throw BlockedOutboundUrlException::unresolvable($host);
        }

        return (string) $records[0];
    }

    private static function isStandAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $packed = inet_pton($ip);
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
}
