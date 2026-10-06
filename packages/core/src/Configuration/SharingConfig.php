<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Support\RandomShareTokenGenerator;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class SharingConfig
{
    public function __construct(private Repository $config) {}

    /** @return class-string<ShareTokenGenerator> */
    public function tokenGenerator(): string
    {
        $class = $this->config->get('vaulter.sharing.token_generator', RandomShareTokenGenerator::class);
        if (!is_string($class) || !is_a($class, ShareTokenGenerator::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.sharing.token_generator must be a FQCN.');
        }

        return $class;
    }

    public function urlBase(): string
    {
        $value = $this->config->get('vaulter.sharing.url_base', '/share');

        return is_string($value) && $value !== '' ? rtrim($value, '/') : '/share';
    }

    public function prefixLength(): int
    {
        $value = $this->config->get('vaulter.sharing.prefix_length', 8);

        $length = is_numeric($value) ? (int) $value : 8;

        return max($length, 1);
    }

    public function defaultTtlHours(): int
    {
        $value = $this->config->get('vaulter.sharing.default_ttl_hours', 72);

        $hours = is_numeric($value) ? (int) $value : 72;

        return max($hours, 1);
    }

    public function maxTtlHours(): int
    {
        $value = $this->config->get('vaulter.sharing.max_ttl_hours', 720);

        $hours = is_numeric($value) ? (int) $value : 720;

        return max($hours, 1);
    }
}
