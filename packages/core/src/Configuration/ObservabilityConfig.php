<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class ObservabilityConfig
{
    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        $value = $this->config->get('vaulter.observability.enabled', false);

        return (bool) $value;
    }

    public function recorder(): ?string
    {
        $value = $this->config->get('vaulter.observability.recorder', null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function tracingEnabled(): bool
    {
        $value = $this->config->get('vaulter.observability.tracing.enabled', false);

        return (bool) $value;
    }

    public function tracingRecorder(): ?string
    {
        $value = $this->config->get('vaulter.observability.tracing.recorder', null);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
