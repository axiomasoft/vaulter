<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\IdStrategy;

/**
 * Static migration helper over the resolved IdStrategy. Hand-written migrations
 * call these helpers instead of hard-coding column types, so "ulid vs uuid7 vs
 * bigint" stays driven by configuration key vaulter.ids.strategy. Key/reference/morphs route through the strategy;
 * hostKey/actor/tenant use the independent host-key type; panel/extra_data/bits/reference add
 * the per-table convention columns from the master plan §2.0.
 */
final class VaulterSchema
{
    /**
     * Define the primary-key column using the configured id strategy.
     */
    public static function key(Blueprint $table, string $column = 'id'): void
    {
        self::strategy()->key(table: $table, column: $column);
    }

    /**
     * Define a typed foreign reference to another Vaulter entity (same key type)
     * plus its index. The explicit ->foreign()->cascadeOnDelete() is added by
     * the migration that uses it — this helper only shapes the column.
     */
    public static function reference(Blueprint $table, string $column, bool $nullable = false): void
    {
        self::strategy()->reference(table: $table, column: $column, nullable: $nullable);
    }

    /**
     * Backward-compatible alias of reference(). Existing migrations call
     * foreignKey(); new code should prefer reference().
     */
    public static function foreignKey(Blueprint $table, string $column, bool $nullable = false): void
    {
        self::reference(table: $table, column: $column, nullable: $nullable);
    }

    /**
     * Define a polymorphic morph pair (*_type varchar + *_id of the key type)
     * with a composite index.
     */
    public static function morphs(Blueprint $table, string $name): void
    {
        $strategy = self::strategy();

        $strategy->reference(table: $table, column: $name.'_id', nullable: false);

        $table->string(column: $name.'_type');
        $table->index(columns: [$name.'_type', $name.'_id']);
    }

    /**
     * Reference to a host record using ids.host_keys, without a foreign key.
     */
    public static function hostKey(Blueprint $table, string $column, bool $nullable = false): void
    {
        HostKeyColumns::add(table: $table, column: $column, nullable: $nullable, index: false);
    }

    /** Reference to a nullable host actor, without an index. */
    public static function actor(Blueprint $table, string $column, bool $nullable = true): void
    {
        self::hostKey(table: $table, column: $column, nullable: $nullable);
    }

    /**
     * Optional tenant column — emitted only when tenancy is enabled. Host-scoped:
     * Vaulter never scopes by it (D8).
     */
    public static function tenant(Blueprint $table): void
    {
        if (!app(TenancyConfig::class)->enabled()) {
            return;
        }

        HostKeyColumns::add(table: $table, column: TenancyConfig::COLUMN, nullable: true, index: true);
    }

    /**
     * Add the panel discriminator column (default-panel root tables).
     */
    public static function panel(Blueprint $table): void
    {
        $table->string(column: 'profile')->default(value: 'default')->index();
    }

    /**
     * Add the optional extra_data jsonb escape-hatch when enabled in config
     * (default true).
     */
    public static function extraData(Blueprint $table): void
    {
        if (!app(VaulterConfig::class)->extraDataEnabled()) {
            return;
        }

        $table->json(column: 'extra_data')->nullable();
    }

    /**
     * Add an unsigned-integer bitmask column (CRUDS permission masks, etc.).
     */
    public static function bits(Blueprint $table, string $column, int $default = 0): void
    {
        $table->unsignedInteger(column: $column)->default(value: $default);
    }

    private static function strategy(): IdStrategy
    {
        return app(IdStrategyResolver::class)->resolve();
    }
}
