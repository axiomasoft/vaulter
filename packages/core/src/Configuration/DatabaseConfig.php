<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class DatabaseConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function tablePrefix(): string
    {
        $value = $this->config->get('vaulter.database.table_prefix', 'v_');

        return is_string($value) ? $value : 'v_';
    }

    public function table(string $name): string
    {
        return $this->tablePrefix().$name;
    }

    public function connection(): ?string
    {
        $value = $this->config->get('vaulter.database.connection');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether writes to Vaulter models outside an operation are refused (vaulter.database.write_guard). */
    public function writeGuard(): bool
    {
        return $this->config->get('vaulter.database.write_guard', true) !== false;
    }
}
