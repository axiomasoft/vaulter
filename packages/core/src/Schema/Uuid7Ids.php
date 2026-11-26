<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;

/**
 * Time-sortable UUIDv7 keys on a native uuid column. In Laravel 11/12 the
 * HasUuids concern already mints Str::uuid7() (ordered) values, so it is the
 * canonical "uuid7" model trait — there is no separate HasVersion7Uuids class.
 */
#[Internal]
final class Uuid7Ids implements IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void
    {
        // Explicit PRIMARY KEY command (not a fluent ->primary() modifier): Laravel
        // appends fluent indexes after the foreign-key commands, so on PostgreSQL a
        // self-referencing FK in the same create (v_nodes.parent_id → v_nodes.id) would
        // be compiled before the PK and rejected. See UlidIds::key() for the full note.
        $table->uuid(column: $column);
        $table->primary($column);
    }

    public function reference(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->uuid(column: $column)->nullable(value: $nullable)->index();
    }

    public function actor(Blueprint $table, string $column, bool $nullable = false): void
    {
        $table->uuid(column: $column)->nullable(value: $nullable);
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
        return HasUuids::class;
    }

    public function newId(): string
    {
        return (string) Str::uuid7();
    }
}
