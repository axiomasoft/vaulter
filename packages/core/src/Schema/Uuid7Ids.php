<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Vaulter\Contracts\IdStrategy;

/**
 * Time-sortable UUIDv7 keys on a native uuid column. In Laravel 11/12 the
 * HasUuids concern already mints Str::uuid7() (ordered) values, so it is the
 * canonical "uuid7" model trait — there is no separate HasVersion7Uuids class.
 */
final class Uuid7Ids implements IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void
    {
        $table->uuid(column: $column)->primary();
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
