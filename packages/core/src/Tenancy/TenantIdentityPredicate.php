<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/** @internal Identifiers compile through the query grammar; tenant values stay bound. */
final readonly class TenantIdentityPredicate implements Expression
{
    public function __construct(private string $column, private string $driver) {}

    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);

        return match ($this->driver) {
            'mysql', 'mariadb' => 'CAST(CAST('.$column.' AS CHAR) AS BINARY) = CAST(? AS BINARY)',
            'pgsql' => 'CAST('.$column.' AS TEXT) COLLATE "C" = ?',
            default => 'CAST('.$column.' AS TEXT) COLLATE BINARY = ?',
        };
    }
}
