<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class TenancyConfig
{
    public function __construct(private Repository $config) {}

    public const string COLUMN = 'tenant_id';

    /** @return class-string<TenantResolver> */
    public function resolver(): string
    {
        $class = $this->config->get('vaulter.tenancy.resolver');
        if (!is_string($class) || !class_exists($class) || !is_a($class, TenantResolver::class, true)) {
            throw new TenancyMisconfiguredException('vaulter.tenancy.resolver must implement TenantResolver when strict tenancy is enabled.');
        }

        return $class;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('vaulter.tenancy.enabled', false);
    }
}
