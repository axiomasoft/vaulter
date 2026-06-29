<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema helpers that honour config('vaulter.id_type') and tenancy, so the
 * published migrations work for both auto-increment and ULID host apps.
 */
final class VaulterSchema
{
    public static function key(Blueprint $table): void
    {
        if (Config::usesUlids()) {
            $table->ulid('id')->primary();

            return;
        }

        $table->id();
    }

    public static function foreignKey(Blueprint $table, string $column, bool $nullable = false): void
    {
        $definition = Config::usesUlids()
            ? $table->ulid($column)
            : $table->unsignedBigInteger($column);

        if ($nullable) {
            $definition->nullable();
        }

        $definition->index();
    }

    public static function morphs(Blueprint $table, string $name): void
    {
        if (Config::usesUlids()) {
            $table->ulidMorphs($name);

            return;
        }

        $table->morphs($name);
    }

    /**
     * Reference to a host record (user id) of unknown-but-configured key type.
     */
    public static function actor(Blueprint $table, string $column): void
    {
        $definition = Config::usesUlids()
            ? $table->ulid($column)
            : $table->unsignedBigInteger($column);

        $definition->nullable();
    }

    public static function tenant(Blueprint $table): void
    {
        if (!Config::tenantEnabled()) {
            return;
        }

        $column = Config::tenantColumn();

        $definition = Config::usesUlids()
            ? $table->ulid($column)
            : $table->unsignedBigInteger($column);

        $definition->nullable()->index();
    }
}
