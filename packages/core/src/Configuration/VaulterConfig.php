<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over the kernel's `config('vaulter.*')` sections. A module reads its own sections through its own
 * config class (`vaulter-drive.*` through the drive's `DriveConfig`), so the surface here stays the kernel's.
 */
#[Api]
final readonly class VaulterConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    /**
     * The outbox model (`vaulter.models.outbox`), which a host may swap for a subclass.
     *
     * @return class-string<OutboxEvent>
     */
    public function outboxModel(): string
    {
        $path = 'vaulter.models.outbox';
        $class = $this->config->get($path, OutboxEvent::class);
        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for '.$path.'.');
        }

        ConfigValidator::assertModel($path, $class, OutboxEvent::class);

        return $class;
    }

    public function database(): DatabaseConfig
    {
        return new DatabaseConfig($this->config);
    }

    public function ids(): IdsConfig
    {
        return new IdsConfig($this->config);
    }

    public function tenancy(): TenancyConfig
    {
        return new TenancyConfig($this->config);
    }

    public function routes(): RoutesConfig
    {
        return new RoutesConfig($this->config);
    }

    public function outbox(): OutboxConfig
    {
        return new OutboxConfig($this->config);
    }

    public function observability(): ObservabilityConfig
    {
        return new ObservabilityConfig($this->config);
    }
}
