<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Packman\Attributes\Internal;

/**
 * Renders the operation catalogue: one row per mutating operation with its module, aggregate, concurrency
 * and idempotency policy and REST routes — as a Markdown table for the reference docs, or as data for `--json`.
 */
#[Internal]
final readonly class OperationsReference
{
    public function __construct(private OperationCatalog $catalog) {}

    /**
     * @return list<array{operation: string, module: string, aggregate: string, concurrency: string, idempotency: string, routes: list<string>}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->catalog->all() as $name => $entry) {
            $rows[] = ['operation' => $name, 'module' => $entry['module'], ...$entry['policy']->toArray()];
        }

        return $rows;
    }

    /** The Markdown table of the catalogue. */
    public function markdown(): string
    {
        $lines = [
            '| Operation | Module | Aggregate | Concurrency | Idempotency | Routes |',
            '|---|---|---|---|---|---|',
        ];
        foreach ($this->rows() as $row) {
            $lines[] = sprintf(
                '| `%s` | %s | `%s` | `%s` | `%s` | %s |',
                $row['operation'],
                $row['module'],
                $row['aggregate'],
                $row['concurrency'],
                $row['idempotency'],
                $row['routes'] === [] ? '—' : implode(', ', array_map(static fn (string $route): string => '`'.$route.'`', $row['routes'])),
            );
        }

        return implode("\n", $lines);
    }
}
