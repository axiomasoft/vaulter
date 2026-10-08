<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class DatabaseConfig
{
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

    public function legacyTable(string $legacyEntity): string
    {
        $mapped = $this->config->get('vaulter.table_names.'.$legacyEntity);
        $base = is_string($mapped) && $mapped !== '' ? $mapped : $legacyEntity;

        return $this->tablePrefix().$base;
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
