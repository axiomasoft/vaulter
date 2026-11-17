<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable transactional outbox and relay settings (`vaulter.outbox`).
 */
#[Api(since: '1.0')]
final readonly class OutboxOptions implements OptionsFactory
{
    public function __construct(
        public int $settleWindowSeconds = 300,
        public int $retentionSeconds = 604800,
        public int $maxConsumerHoldSeconds = 604800,
        public int $deadRetentionSeconds = 2592000,
        public int $leaseSeconds = 60,
        public int $batch = 100,
        public int $maxAttempts = 10,
        public int $backoffBaseSeconds = 2,
        public int $backoffCapSeconds = 3600,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.outbox'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys(
            $config,
            [
                'settle_window_seconds',
                'retention_seconds',
                'max_consumer_hold_seconds',
                'dead_retention_seconds',
                'lease_seconds',
                'batch',
                'max_attempts',
                'backoff_base_seconds',
                'backoff_cap_seconds',
            ],
            $path,
        );

        $settleWindow = self::positiveInt($validator, $path, 'settle_window_seconds', $config['settle_window_seconds'] ?? 300);
        $retention = self::positiveInt($validator, $path, 'retention_seconds', $config['retention_seconds'] ?? 604800);
        $maxConsumerHold = self::positiveInt($validator, $path, 'max_consumer_hold_seconds', $config['max_consumer_hold_seconds'] ?? 604800);
        $deadRetention = self::positiveInt($validator, $path, 'dead_retention_seconds', $config['dead_retention_seconds'] ?? 2592000);
        $lease = self::positiveInt($validator, $path, 'lease_seconds', $config['lease_seconds'] ?? 60);
        $batch = self::positiveInt($validator, $path, 'batch', $config['batch'] ?? 100, 10000);
        $maxAttempts = self::positiveInt($validator, $path, 'max_attempts', $config['max_attempts'] ?? 10, 1000);
        $backoffBase = self::positiveInt($validator, $path, 'backoff_base_seconds', $config['backoff_base_seconds'] ?? 2);
        $backoffCap = self::positiveInt($validator, $path, 'backoff_cap_seconds', $config['backoff_cap_seconds'] ?? 3600);

        if ($backoffCap < $backoffBase) {
            $validator->add(path: $path.'.backoff_cap_seconds', message: 'must be at least vaulter.outbox.backoff_base_seconds');
        }

        if ($maxConsumerHold < $settleWindow) {
            $validator->add(path: $path.'.max_consumer_hold_seconds', message: 'must be at least vaulter.outbox.settle_window_seconds');
        }

        $validator->failIfAny();

        return new self(
            settleWindowSeconds: $settleWindow,
            retentionSeconds: $retention,
            maxConsumerHoldSeconds: $maxConsumerHold,
            deadRetentionSeconds: $deadRetention,
            leaseSeconds: $lease,
            batch: $batch,
            maxAttempts: $maxAttempts,
            backoffBaseSeconds: $backoffBase,
            backoffCapSeconds: $backoffCap,
        );
    }

    private static function positiveInt(
        ConfigurationValidator $validator,
        string $basePath,
        string $key,
        mixed $value,
        int $max = PHP_INT_MAX,
    ): int {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1 || $value > $max) {
            $validator->add(
                path: $basePath.'.'.$key,
                message: "must be an integer between 1 and {$max}. Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/reference/config.md",
            );

            return 1;
        }

        return $value;
    }
}
