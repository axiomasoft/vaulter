<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over config('vaulter.outbox.*'), the transactional outbox and its relay (D29). Every value is
 * a positive integer; an invalid one is refused rather than clamped, since a silently clamped settle window
 * could let reconciliation skip a late commit.
 */
#[Api]
final readonly class OutboxConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    /** Seconds an event must be old before reconciliation trusts that no smaller seq can still commit. */
    public function settleWindowSeconds(): int
    {
        return $this->positiveInteger('settle_window_seconds', 300);
    }

    /** Seconds an event stays in v_outbox once every consumer that holds retention is past it. */
    public function retentionSeconds(): int
    {
        return $this->positiveInteger('retention_seconds', 604800);
    }

    /** Seconds a paused durable consumer holds retention before it is detached with an explicit gap. */
    public function maxConsumerHoldSeconds(): int
    {
        return $this->positiveInteger('max_consumer_hold_seconds', 604800);
    }

    /** Seconds a dead delivery row is kept for inspection and replay. */
    public function deadRetentionSeconds(): int
    {
        return $this->positiveInteger('dead_retention_seconds', 2592000);
    }

    /** Seconds a relay worker owns a leased delivery (and a reconciler its consumer) before a takeover. */
    public function leaseSeconds(): int
    {
        return $this->positiveInteger('lease_seconds', 60);
    }

    /** Deliveries one relay pass leases per consumer. */
    public function batch(): int
    {
        return $this->positiveInteger('batch', 100, 10000);
    }

    /** Attempts after which a retried delivery is dead. */
    public function maxAttempts(): int
    {
        return $this->positiveInteger('max_attempts', 10, 1000);
    }

    public function backoffBaseSeconds(): int
    {
        return $this->positiveInteger('backoff_base_seconds', 2);
    }

    public function backoffCapSeconds(): int
    {
        return $this->positiveInteger('backoff_cap_seconds', 3600);
    }

    /**
     * Every key, read once: the settle window must be at least one second and a hold at least the window, or a
     * paused consumer could be detached before its last events settled.
     *
     * @throws InvalidConfigurationException
     */
    public function validate(): void
    {
        $settle = $this->settleWindowSeconds();
        $this->retentionSeconds();
        $this->deadRetentionSeconds();
        $this->leaseSeconds();
        $this->batch();
        $this->maxAttempts();
        $this->backoffBaseSeconds();
        if ($this->backoffCapSeconds() < $this->backoffBaseSeconds()) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.outbox.backoff_cap_seconds must be at least vaulter.outbox.backoff_base_seconds.');
        }
        if ($this->maxConsumerHoldSeconds() < $settle) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.outbox.max_consumer_hold_seconds must be at least vaulter.outbox.settle_window_seconds.');
        }
    }

    /** @throws InvalidConfigurationException */
    private function positiveInteger(string $key, int $default, int $max = PHP_INT_MAX): int
    {
        $value = $this->config->get('vaulter.outbox.'.$key, $default);
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidConfigurationException(
                "[Vaulter] vaulter.outbox.{$key} must be an integer between 1 and {$max}. "
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/reference/config.md',
            );
        }

        return $value;
    }
}
