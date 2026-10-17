<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Drive\Support\RandomShareTokenGenerator;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class SharingConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    /** @return class-string<ShareTokenGenerator> */
    public function tokenGenerator(): string
    {
        $class = $this->config->get('vaulter-drive.sharing.token_generator', RandomShareTokenGenerator::class);
        if (!is_string($class) || !is_a($class, ShareTokenGenerator::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-drive.sharing.token_generator must be a FQCN.');
        }

        return $class;
    }

    public function urlBase(): string
    {
        $value = $this->config->get('vaulter-drive.sharing.url_base', '/share');

        return is_string($value) && $value !== '' ? rtrim($value, '/') : '/share';
    }

    public function prefixLength(): int
    {
        $value = $this->config->get('vaulter-drive.sharing.prefix_length', 8);

        $length = is_numeric($value) ? (int) $value : 8;

        return max($length, 1);
    }

    public function defaultTtlHours(): int
    {
        $value = $this->config->get('vaulter-drive.sharing.default_ttl_hours', 72);

        $hours = is_numeric($value) ? (int) $value : 72;

        return max($hours, 1);
    }

    public function maxTtlHours(): int
    {
        $value = $this->config->get('vaulter-drive.sharing.max_ttl_hours', 720);

        $hours = is_numeric($value) ? (int) $value : 720;

        return max($hours, 1);
    }
}
