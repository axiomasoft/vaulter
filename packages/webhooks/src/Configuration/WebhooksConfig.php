<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Security\CidrRange;
use Vaulter\Webhooks\WebhooksSchedule;

/**
 * Typed accessors over config('vaulter-webhooks.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class WebhooksConfig
{
    /** @internal Floor of the webhooks.blocked-ranges production invariant. */
    public const array DEFAULT_BLOCKED_IP_RANGES = [
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
        // Not public destinations: IETF protocol assignments, TEST-NET-1/2/3, benchmarking.
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        // Forms that embed an IPv4 address and may route to an internal one: IPv4-compatible,
        // NAT64 (RFC 6052 / 8215), 6to4 and Teredo; plus documentation and discard prefixes.
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
        '2001::/32',
        '2001:db8::/32',
        '100::/64',
    ];

    private const array DEFAULT_RETRY_SCHEDULE = [5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];

    private const int DEFAULT_ROTATION_OVERLAP = 86400;

    private const int MAX_ROTATION_OVERLAP = 604800;

    /** Keys of the pre-1.0 delivery policy and the replacement each one is refused with. */
    public const array REMOVED_KEYS = [
        'max_attempts' => 'vaulter-webhooks.retry_schedule (attempts = entries + 1)',
        'backoff' => 'vaulter-webhooks.retry_schedule',
        'disable_after_failures' => 'vaulter-webhooks.disable_after_days',
        'signature_header' => 'the Standard Webhooks headers webhook-id, webhook-timestamp and webhook-signature, which are fixed',
        'timestamp_header' => 'the Standard Webhooks headers webhook-id, webhook-timestamp and webhook-signature, which are fixed',
        'event_header' => 'the event type, which is the "type" field of the delivery body',
        'relay.claim_seconds' => 'vaulter.outbox.lease_seconds (the webhooks.fanout consumer runs under the outbox delivery lease, D29)',
    ];

    #[Internal]
    public function __construct(private Repository $config) {}

    /**
     * The model class behind `webhook_endpoint` / `webhook_delivery`: the configured one (a subclass of the shipped model) or the shipped one.
     *
     *
     * @throws InvalidConfigurationException when the key is unknown or the configured class does not extend the shipped model
     *
     * @return ($key is 'webhook_endpoint' ? class-string<WebhookEndpoint> : class-string<WebhookDelivery>)
     */
    public function model(string $key): string
    {
        return match ($key) {
            'webhook_endpoint' => $this->configuredModel($key, WebhookEndpoint::class),
            'webhook_delivery' => $this->configuredModel($key, WebhookDelivery::class),
            default => throw new InvalidConfigurationException('[Vaulter] Unknown webhooks model key "'.$key.'".'),
        };
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T> $default
     * @return class-string<T>
     */
    private function configuredModel(string $key, string $default): string
    {
        $path = 'vaulter-webhooks.models.'.$key;
        $class = $this->config->get($path, $default);
        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for '.$path.'.');
        }

        ConfigValidator::assertModel($path, $class, $default);

        return $class;
    }

    /**
     * Reads every validated accessor once, so a malformed `vaulter-webhooks.*` value fails at boot of the active module.
     *
     * @throws InvalidConfigurationException
     */
    public function validate(): void
    {
        $this->assertNoRemovedKeys();
        $this->urlGuard();
        $this->allowedIpRanges();
        $this->blockedIpRanges();
        $this->retrySchedule();
        $this->retryJitter();
        $this->relayFanoutChunk();
        $this->model('webhook_endpoint');
        $this->model('webhook_delivery');
        (new ScheduleConfig($this->config))->frequencies('vaulter-webhooks', WebhooksSchedule::KEYS);
    }

    /** @throws InvalidConfigurationException when a pre-1.0 delivery key is still set */
    public function assertNoRemovedKeys(): void
    {
        foreach (self::REMOVED_KEYS as $key => $replacement) {
            if ($this->config->has('vaulter-webhooks.'.$key)) {
                throw new InvalidConfigurationException(
                    "[Vaulter] vaulter-webhooks.{$key} was removed: use {$replacement}. "
                    .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/reference/config.md',
                );
            }
        }
    }

    public function timeout(): int
    {
        $value = $this->config->get('vaulter-webhooks.timeout', 15);

        $seconds = is_numeric($value) ? (int) $value : 15;

        return max($seconds, 1);
    }

    /**
     * Seconds to wait after the n-th failed attempt (1-based) before the next one. The
     * schedule length plus one is the attempt budget; a delivery that fails past the
     * last entry is dead.
     *
     * @return list<int>
     */
    public function retrySchedule(): array
    {
        $schedule = $this->config->get('vaulter-webhooks.retry_schedule', self::DEFAULT_RETRY_SCHEDULE);

        if (!is_array($schedule)) {
            return self::DEFAULT_RETRY_SCHEDULE;
        }

        $values = [];
        foreach ($schedule as $seconds) {
            if (!is_numeric($seconds) || (int) $seconds < 0) {
                throw new InvalidConfigurationException('[Vaulter] vaulter-webhooks.retry_schedule must be a list of non-negative seconds.');
            }
            $values[] = (int) $seconds;
        }

        return $values;
    }

    /** Fraction (0..0.5) of a retry delay randomly added or removed so retries do not align. */
    public function retryJitter(): float
    {
        $value = $this->config->get('vaulter-webhooks.retry_jitter', 0.1);

        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value > 0.5) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-webhooks.retry_jitter must be a number between 0 and 0.5.');
        }

        return (float) $value;
    }

    /** Days of uninterrupted failure after which an endpoint is disabled; 0 never disables. */
    public function disableAfterDays(): int
    {
        $value = $this->config->get('vaulter-webhooks.disable_after_days', 5);

        $days = is_numeric($value) ? (int) $value : 5;

        return max($days, 0);
    }

    /** Seconds the previous secret keeps signing after a rotation (capped at seven days). */
    public function secretRotationOverlap(): int
    {
        $value = $this->config->get('vaulter-webhooks.secret_rotation_overlap', self::DEFAULT_ROTATION_OVERLAP);

        $seconds = is_numeric($value) ? (int) $value : self::DEFAULT_ROTATION_OVERLAP;

        return min(max($seconds, 0), self::MAX_ROTATION_OVERLAP);
    }

    public function retentionDays(): int
    {
        $value = $this->config->get('vaulter-webhooks.retention_days', 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    public function manageAbility(): ?string
    {
        $value = $this->config->get('vaulter-webhooks.manage_ability');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return class-string<OutboundUrlGuard> */
    public function urlGuard(): string
    {
        $class = $this->config->get('vaulter-webhooks.delivery.url_guard', DefaultOutboundUrlGuard::class);
        if (!is_string($class) || !is_a($class, OutboundUrlGuard::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-webhooks.delivery.url_guard must be a FQCN.');
        }

        return $class;
    }

    /** @return list<string> */
    public function allowedSchemes(): array
    {
        $value = $this->config->get('vaulter-webhooks.delivery.allowed_schemes', ['http', 'https']);

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
        return $this->ipRanges('vaulter-webhooks.delivery.blocked_ip_ranges', self::DEFAULT_BLOCKED_IP_RANGES);
    }

    /** @return list<string> */
    public function allowedIpRanges(): array
    {
        return $this->ipRanges('vaulter-webhooks.delivery.allowed_ip_ranges', []);
    }

    public function connectTimeout(): int
    {
        $value = $this->config->get('vaulter-webhooks.delivery.connect_timeout', 2);

        $seconds = is_numeric($value) ? (int) $value : 2;

        return max($seconds, 1);
    }

    public function maxResponseBytes(): int
    {
        $value = $this->config->get('vaulter-webhooks.delivery.max_response_bytes', 65536);

        $bytes = is_numeric($value) ? (int) $value : 65536;

        return max($bytes, 0);
    }

    public function retryClaimSeconds(): int
    {
        $value = $this->config->get('vaulter-webhooks.retry_claim_seconds', 60);

        $seconds = is_numeric($value) ? (int) $value : 60;

        return max($seconds, 1);
    }

    /**
     * How many endpoints one fan-out transaction materializes. Out-of-range values
     * are refused rather than clamped: a silently clamped chunk would hide a typo
     * that changes how long one fan-out transaction holds its locks.
     */
    public function relayFanoutChunk(): int
    {
        $value = $this->config->get('vaulter-webhooks.relay.fanout_chunk', 100);

        $chunk = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]])
            : false;
        if ($chunk === false) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-webhooks.relay.fanout_chunk must be an integer between 1 and 1000.');
        }

        return $chunk;
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
            if (!is_string($range) || CidrRange::parse($range) === null) {
                throw new InvalidConfigurationException('[Vaulter] '.$key.' must contain only valid IP or CIDR strings.');
            }

            $ranges[] = $range;
        }

        return $ranges;
    }
}
