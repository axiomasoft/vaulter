<?php

declare(strict_types=1);

namespace Vaulter\Contracts;

use Illuminate\Database\Schema\Blueprint;

/**
 * Primary-key strategy. Drives column types in the published (and later
 * generated) migrations plus the matching model concern, so "ulid vs uuid7 vs
 * bigint" is decided from configuration key vaulter.ids.strategy rather than hard-coded.
 *
 * Vaulter defaults to ulid so morph keys line up with the Corex/host authorization
 * ecosystem; uuid7 maps onto native Postgres uuid columns and bigint is the
 * legacy auto-increment path. Implementations live in the Schema namespace.
 */
interface IdStrategy
{
    /**
     * Define the primary-key column on the table.
     */
    public function key(Blueprint $table, string $column = 'id'): void;

    /**
     * Define a foreign reference to another Vaulter entity (same key type).
     * The explicit ->foreign()->cascadeOnDelete() is added by the migration.
     */
    public function reference(Blueprint $table, string $column, bool $nullable = false): void;

    /**
     * Define a reference to an actor (host user id) — no DB-level FK, because
     * the consuming application owns the users table.
     */
    public function actor(Blueprint $table, string $column, bool $nullable = false): void;

    /**
     * Eloquent cast for the key type.
     */
    public function cast(): string;

    /**
     * Model concern (trait FQCN) applying this key type, or null for bigint.
     *
     * @return class-string|null
     */
    public function modelConcern(): ?string;

    /**
     * Mint a fresh key for the creating event, or null for auto-increment keys
     * (where the database assigns the value).
     */
    public function newId(): string|int|null;
}
