<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Vaulter\Contracts\IdStrategy;

/**
 * Lexicographically sortable ULID keys (26-char Crockford base32). The default
 * strategy (D9) — its morph keys line up with the Corex/Azgard ecosystem.
 */
final class UlidIds implements IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void
    {
        $table->char(column: $column, length: 26)->primary();
    }

    public function reference(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->char(column: $column, length: 26)->nullable(value: $nullable)->index();
    }

    public function actor(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->char(column: $column, length: 26)->nullable(value: $nullable);
    }

    public function cast(): string
    {
        return 'string';
    }

    /**
     * @return class-string
     */
    public function modelConcern(): string
    {
        return HasUlids::class;
    }

    public function newId(): string
    {
        return (string) Str::ulid();
    }
}
