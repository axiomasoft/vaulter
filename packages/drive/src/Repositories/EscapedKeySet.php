<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Packman\Attributes\Internal;

/**
 * An IN predicate with driver-escaped values and no bind parameters.
 * Escaping happens when the query compiles, using that query's grammar/connection.
 */
#[Internal]
final readonly class EscapedKeySet implements Expression
{
    /**
     * @param list<int|string> $ids
     */
    public function __construct(
        private string $column,
        private array $ids,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $literals = array_map(static fn (int|string $id): string => $grammar->escape($id), $this->ids);

        return $grammar->wrap($this->column).' in ('.implode(', ', $literals).')';
    }
}
