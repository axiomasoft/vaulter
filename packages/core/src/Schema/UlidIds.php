<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;

/**
 * Lexicographically sortable ULID keys (26-char Crockford base32). The default
 * strategy — its morph keys line up with the host authorization ecosystem.
 */
#[Internal]
final class UlidIds implements IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void
    {
        // Declare the PRIMARY KEY as an explicit command at key() time rather than a
        // fluent ->primary() modifier. Laravel appends fluent indexes LAST (after the
        // table's foreign-key commands), so on PostgreSQL a SELF-referencing FK added in
        // the same create (v_nodes.parent_id → v_nodes.id) would be compiled BEFORE the
        // PK and rejected with "no unique constraint matching given keys". An explicit
        // command keeps the PK ahead of the foreign keys. (bigIncrements is immune — its
        // PK is inlined into CREATE TABLE.)
        $table->char(column: $column, length: 26);
        $table->primary($column);
    }

    public function reference(Blueprint $table, string $column, bool $nullable = false, bool $index = true): void
    {
        $definition = $table->char(column: $column, length: 26)->nullable(value: $nullable);

        if ($index) {
            $definition->index();
        }
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
