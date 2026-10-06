<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class TenancyConfig
{
    public function __construct(private Repository $config) {}

    public const string COLUMN = 'tenant_id';

    public function enabled(): bool
    {
        return (bool) $this->config->get('vaulter.tenancy.enabled', false);
    }
}
