<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class WebhooksConfig
{
    private const array DEFAULT_BLOCKED_IP_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    public function __construct(private Repository $config) {}

    public function timeout(): int
    {
        $value = $this->config->get('vaulter.webhooks.timeout', 5);

        $seconds = is_numeric($value) ? (int) $value : 5;

        return max($seconds, 1);
    }

    public function maxAttempts(): int
    {
        $value = $this->config->get('vaulter.webhooks.max_attempts', 5);

        $attempts = is_numeric($value) ? (int) $value : 5;

        return max($attempts, 1);
    }

    public function backoff(int $attempt): int
    {
        $schedule = $this->config->get('vaulter.webhooks.backoff', [10, 30, 60, 300, 1800]);

        if (!is_array($schedule) || $schedule === []) {
            return 0;
        }

        $values = array_values(array_map(static fn (mixed $seconds): int => is_numeric($seconds) ? (int) $seconds : 0, $schedule));

        $index = max(0, $attempt - 1);

        return $values[$index] ?? $values[array_key_last($values)];
    }

    public function disableAfterFailures(): int
    {
        $value = $this->config->get('vaulter.webhooks.disable_after_failures', 10);

        $failures = is_numeric($value) ? (int) $value : 10;

        return max($failures, 1);
    }

    public function signatureHeader(): string
    {
        $value = $this->config->get('vaulter.webhooks.signature_header', 'X-Vaulter-Signature');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Signature';
    }

    public function timestampHeader(): string
    {
        $value = $this->config->get('vaulter.webhooks.timestamp_header', 'X-Vaulter-Timestamp');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Timestamp';
    }

    public function eventHeader(): string
    {
        $value = $this->config->get('vaulter.webhooks.event_header', 'X-Vaulter-Event');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Event';
    }

    public function retentionDays(): int
    {
        $value = $this->config->get('vaulter.webhooks.retention_days', 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    public function manageAbility(): ?string
    {
        $value = $this->config->get('vaulter.webhooks.manage_ability');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return class-string<OutboundUrlGuard> */
    public function urlGuard(): string
    {
        $class = $this->config->get('vaulter.webhooks.delivery.url_guard', DefaultOutboundUrlGuard::class);
        if (!is_string($class) || !is_a($class, OutboundUrlGuard::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.webhooks.delivery.url_guard must be a FQCN.');
        }

        return $class;
    }

    /** @return list<string> */
    public function allowedSchemes(): array
    {
        $value = $this->config->get('vaulter.webhooks.delivery.allowed_schemes', ['http', 'https']);

        if (!is_array($value)) {
            return ['http', 'https'];
        }

        $schemes = [];

        foreach ($value as $scheme) {
            if (is_string($scheme) && $scheme !== '') {
                $schemes[] = strtolower($scheme);
            }
        }

        return $schemes === [] ? ['http', 'https'] : array_values(array_unique($schemes));
    }

    /** @return list<string> */
    public function blockedIpRanges(): array
    {
        return $this->ipRanges('vaulter.webhooks.delivery.blocked_ip_ranges', self::DEFAULT_BLOCKED_IP_RANGES);
    }

    /** @return list<string> */
    public function allowedIpRanges(): array
    {
        return $this->ipRanges('vaulter.webhooks.delivery.allowed_ip_ranges', []);
    }

    public function connectTimeout(): int
    {
        $value = $this->config->get('vaulter.webhooks.delivery.connect_timeout', 2);

        $seconds = is_numeric($value) ? (int) $value : 2;

        return max($seconds, 1);
    }

    public function maxResponseBytes(): int
    {
        $value = $this->config->get('vaulter.webhooks.delivery.max_response_bytes', 65536);

        $bytes = is_numeric($value) ? (int) $value : 65536;

        return max($bytes, 0);
    }

    public function retryClaimSeconds(): int
    {
        $value = $this->config->get('vaulter.webhooks.retry_claim_seconds', 60);

        $seconds = is_numeric($value) ? (int) $value : 60;

        return max($seconds, 1);
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function ipRanges(string $key, array $default): array
    {
        $value = $this->config->get($key, $default);

        if (!is_array($value)) {
            return $default;
        }

        $ranges = [];

        foreach ($value as $range) {
            if (is_string($range) && $range !== '') {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }
}
