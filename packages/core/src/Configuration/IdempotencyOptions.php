<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Idempotency\IdempotencyClaim;

/**
 * Immutable idempotency receipt retention configuration (`vaulter.idempotency`).
 */
#[Api(since: '1.0')]
final readonly class IdempotencyOptions implements OptionsFactory
{
    public function __construct(
        public int $ttlSeconds = IdempotencyClaim::DEFAULT_TTL_SECONDS,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.idempotency'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['ttl_seconds'], $path);

        $rawTtl = $config['ttl_seconds'] ?? IdempotencyClaim::DEFAULT_TTL_SECONDS;
        $ttlSeconds = IdempotencyClaim::DEFAULT_TTL_SECONDS;
        if (!is_int($rawTtl) || $rawTtl < 1) {
            $validator->add(path: $path.'.ttl_seconds', message: 'must be a positive integer (seconds)');
        } else {
            $ttlSeconds = $rawTtl;
        }

        $validator->failIfAny();

        return new self(ttlSeconds: $ttlSeconds);
    }
}
