<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The schema-ddl adapter: the DDL snapshots of
 * packages/core/tests/Fixtures/Schema/expected/<engine>/<preset>.json compared on the keys preset + table + column, named
 * index and named constraint; a foreign key by what it binds (from → table.to), not by the position the engine numbers it.
 * Column order, map order and `create_sql` (the engine's print of the same structure) are no contract.
 *
 * A gone table or column is BREAK; a new table is ADD; anything else on an existing table — a new column (NOT NULL or not),
 * a nullable column turned NOT NULL, a type, default, index, constraint or foreign key change — is REVIEW with migration and
 * upgrade evidence. A static comparison of one engine does not qualify another: pgsql/mysql stay `pending` in the manifest.
 *
 * @phpstan-import-type ChangeRow from Change
 *
 * @phpstan-type Table array{columns: array<string, array<string, mixed>>, indexes: array<string, mixed>, constraints: array<string, mixed>, foreign_keys: array<string, mixed>}
 * @phpstan-type Snapshot array{preset: string, driver: string, tables: array<string, Table>}
 */
final class SchemaDdl
{
    public const DRIVERS = ['sqlite', 'pgsql', 'mysql'];

    private const TABLE_KEYS = ['columns', 'indexes', 'constraints', 'foreign_keys', 'create_sql'];

    /** @return Snapshot */
    public static function load(string $json, string $where): array
    {
        $data = SnapshotManifest::decode($json, $where);
        SnapshotManifest::keys($data, ['tables', 'preset', 'driver', 'source'], ['tables', 'preset', 'driver'], $where);
        if (!is_string($data['preset']) || $data['preset'] === '' || !in_array($data['driver'], self::DRIVERS, true)) {
            throw new ContractError("{$where}: unknown preset or driver");
        }
        if (!is_array($data['tables']) || array_is_list($data['tables']) && $data['tables'] !== []) {
            throw new ContractError("{$where}: tables must be a map");
        }
        $tables = [];
        foreach ($data['tables'] as $name => $table) {
            $name = (string) $name;
            SnapshotManifest::keys($table, self::TABLE_KEYS, ['columns'], "{$where} {$name}");
            if (!is_array($table['columns']) || !array_is_list($table['columns'])) {
                throw new ContractError("{$where} {$name}: columns must be a list");
            }
            $columns = [];
            foreach ($table['columns'] as $i => $column) {
                if (!is_array($column) || !is_string($column['name'] ?? null) || $column['name'] === '') {
                    throw new ContractError("{$where} {$name}: column #{$i} has no name");
                }
                if (isset($columns[$column['name']])) {
                    throw new ContractError("{$where} {$name}: duplicate column {$column['name']}");
                }
                $columns[$column['name']] = self::column($column, $data['driver'], "{$where} {$name}.{$column['name']}");
            }
            ksort($columns, SORT_STRING);
            $tables[$name] = [
                'columns' => $columns,
                'indexes' => self::named($table['indexes'] ?? [], "{$where} {$name} indexes"),
                'constraints' => self::named($table['constraints'] ?? [], "{$where} {$name} constraints"),
                'foreign_keys' => self::foreignKeys($table['foreign_keys'] ?? [], "{$where} {$name} foreign_keys"),
            ];
        }
        ksort($tables, SORT_STRING);

        return ['preset' => $data['preset'], 'driver' => $data['driver'], 'tables' => $tables];
    }

    /**
     * @param  Snapshot        $before
     * @param  Snapshot        $after
     * @return list<ChangeRow>
     */
    public static function compare(string $surface, array $before, array $after): array
    {
        if ($before['preset'] !== $after['preset'] || $before['driver'] !== $after['driver']) {
            throw new ContractError("{$surface}: preset or driver differs between the snapshots compared");
        }
        $at = "{$after['driver']}:{$after['preset']}";
        $changes = [];
        $add = static function (string $subject, string $rule, string $classification, mixed $from, mixed $to, string $why) use (&$changes, $surface, $at): void {
            $changes[] = Change::make($surface, "{$at}:{$subject}", $rule, $classification, $from, $to, "{$at}:{$subject}: {$why}");
        };

        foreach ($before['tables'] as $name => $table) {
            $next = $after['tables'][$name] ?? null;
            if ($next === null) {
                $add($name, 'table-removed', Change::BREAK, $table, null, 'the table is gone.');

                continue;
            }
            foreach ($table['columns'] as $column => $definition) {
                $new = $next['columns'][$column] ?? null;
                if ($new === null) {
                    $add("{$name}.{$column}", 'column-removed', Change::BREAK, $definition, null, 'a column of an existing table is gone.');
                } elseif (Change::canonical($new) !== Change::canonical($definition)) {
                    $tightened = $definition['nullable'] === true && $new['nullable'] === false;
                    $add("{$name}.{$column}", $tightened ? 'column-not-null' : 'column-changed', Change::REVIEW, $definition, $new, $tightened
                        ? 'a nullable column became NOT NULL: existing rows and writers can fail.'
                        : 'the column definition (type, nullability, default) changed; needs migration and upgrade evidence.');
                }
            }
            foreach ($next['columns'] as $column => $definition) {
                if (!isset($table['columns'][$column])) {
                    $required = $definition['nullable'] === false && $definition['default'] === null;
                    $add("{$name}.{$column}", $required ? 'column-added-required' : 'column-added', Change::REVIEW, null, $definition, $required
                        ? 'a new NOT NULL column without default on an existing table: existing writers fail.'
                        : 'a new column of an existing table needs migration and upgrade evidence.');
                }
            }
            foreach (['indexes' => 'index', 'constraints' => 'constraint', 'foreign_keys' => 'foreign-key'] as $group => $what) {
                foreach (array_unique([...array_keys($table[$group]), ...array_keys($next[$group])]) as $key) {
                    $old = $table[$group][$key] ?? null;
                    $new = $next[$group][$key] ?? null;
                    if (Change::canonical($old) !== Change::canonical($new)) {
                        $rule = $what.'-'.($old === null ? 'added' : ($new === null ? 'removed' : 'changed'));
                        $add("{$name}#{$key}", $rule, Change::REVIEW, $old, $new, "the {$what} changed: writes it now rejects or reads it no longer serves need migration evidence.");
                    }
                }
            }
        }
        foreach ($after['tables'] as $name => $table) {
            if (!isset($before['tables'][$name])) {
                $add($name, 'table-added', Change::ADD, null, $table, 'a new table.');
            }
        }

        return Change::sorted($changes);
    }

    /**
     * A column with its nullability in one form (`nullable`: sqlite notnull, pgsql not_null, mysql nullable YES/NO).
     *
     * @param  array<string, mixed> $column
     * @return array<string, mixed>
     */
    private static function column(array $column, string $driver, string $where): array
    {
        [$field, $nullable] = match ($driver) {
            'sqlite' => ['notnull', static fn (mixed $v): ?bool => in_array($v, [0, 1], true) ? $v === 0 : null],
            'pgsql' => ['not_null', static fn (mixed $v): ?bool => is_bool($v) ? !$v : null],
            default => ['nullable', static fn (mixed $v): ?bool => in_array($v, ['YES', 'NO'], true) ? $v === 'YES' : null],
        };
        $value = array_key_exists($field, $column) ? $nullable($column[$field]) : null;
        if ($value === null || !array_key_exists('default', $column)) {
            throw new ContractError("{$where}: no nullability or default in the {$driver} form");
        }
        unset($column[$field]);
        $column['nullable'] = $value;
        ksort($column, SORT_STRING);

        return $column;
    }

    /** @return array<string, mixed> */
    private static function named(mixed $map, string $where): array
    {
        if (!is_array($map) || array_is_list($map) && $map !== []) {
            throw new ContractError("{$where}: must be a map by name");
        }
        $map = array_map(Change::canonical(...), $map);
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * Foreign keys re-keyed by what they bind; the positional id an engine gives them ("0.0") is dropped.
     *
     * @return array<string, mixed>
     */
    private static function foreignKeys(mixed $keys, string $where): array
    {
        if (!is_array($keys)) {
            throw new ContractError("{$where}: must be a map or a list");
        }
        $found = [];
        foreach ($keys as $key) {
            if (!is_array($key) || !is_string($key['from'] ?? null) || !is_string($key['table'] ?? null)) {
                throw new ContractError("{$where}: a foreign key without from/table");
            }
            $id = "{$key['from']}->{$key['table']}.".(is_string($key['to'] ?? null) ? $key['to'] : '');
            if (isset($found[$id])) {
                throw new ContractError("{$where}: duplicate foreign key {$id}");
            }
            $found[$id] = Change::canonical($key);
        }
        ksort($found, SORT_STRING);

        return $found;
    }
}
