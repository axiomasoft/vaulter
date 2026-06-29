<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Contracts\IdStrategy;

/**
 * Classic auto-incrementing bigint keys (legacy id_type=id). References and
 * actors carry the matching unsigned-bigint type; the database assigns the
 * value, so no model concern and no generated key are needed.
 */
final class AutoIncrementIds implements IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void
    {
        $table->bigIncrements(column: $column);
    }

    public function reference(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->unsignedBigInteger(column: $column)->nullable(value: $nullable)->index();
    }

    public function actor(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->unsignedBigInteger(column: $column)->nullable(value: $nullable);
    }

    public function cast(): string
    {
        return 'integer';
    }

    public function modelConcern(): ?string
    {
        return null;
    }

    public function newId(): string|int|null
    {
        return null;
    }
}
