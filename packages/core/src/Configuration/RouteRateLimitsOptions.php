<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable route rate-limit thresholds (`vaulter.routes.rate_limits`).
 */
#[Api(since: '1.0')]
final readonly class RouteRateLimitsOptions implements OptionsFactory
{
    public function __construct(
        public int $public = 60,
        public int $uploads = 120,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.routes.rate_limits'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['public', 'uploads'], $path);

        $public = self::limit($validator, $path.'.public', $config['public'] ?? 60, 60);
        $uploads = self::limit($validator, $path.'.uploads', $config['uploads'] ?? 120, 120);

        $validator->failIfAny();

        return new self(public: $public, uploads: $uploads);
    }

    private static function limit(ConfigurationValidator $validator, string $path, mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return max($value, 1);
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return max((int) $value, 1);
        }

        if ($value === null) {
            return $fallback;
        }

        $validator->add(path: $path, message: 'must be a positive integer per minute');

        return $fallback;
    }
}
