<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The raw catalog oracle of the installed schema (PLAN3.P1.10): what the engine itself reports for every
 * table, column, index and constraint, as plain text. Schema::getColumns()/getIndexes() are deliberately not
 * used — they drop partial predicates, expressions, collations, generated expressions and constraint options.
 *
 * Only names the engine invents per run (MySQL's AUTO_INCREMENT counter) are normalised; nothing semantic is.
 * Equality is per engine: PostgreSQL, MySQL and SQLite never compare against each other.
 */
final class SchemaSnapshot
{
    /** @return array{tables: array<string, array<string, mixed>>} */
    public static function capture(Connection $db): array
    {
        $driver = $db->getDriverName();
        $tables = [];

        foreach (self::tableNames($db, $driver) as $table) {
            $tables[$table] = match ($driver) {
                'sqlite' => self::sqliteTable($db, $table),
                'pgsql' => self::pgsqlTable($db, $table),
                'mysql', 'mariadb' => self::mysqlTable($db, $table),
                default => throw new RuntimeException('Unsupported schema snapshot driver ['.$driver.'].'),
            };
        }

        ksort($tables);

        return ['tables' => $tables];
    }

    /**
     * Every difference between two snapshots, one line per table/part/name, e.g.
     * `v_nodes.indexes.v_nodes_sibling_uniq: expected "…" actual "…"`.
     *
     * @param  array{tables: array<string, array<string, mixed>>} $expected
     * @param  array{tables: array<string, array<string, mixed>>} $actual
     * @return list<string>
     */
    public static function differences(array $expected, array $actual): array
    {
        $lines = [];
        self::diff(self::comparable($expected['tables']), self::comparable($actual['tables']), '', $lines);

        return $lines;
    }

    /**
     * MySQL lists the keys of SHOW CREATE TABLE in the order they were created. That order is physical history, not
     * schema, so the key lines are compared sorted; columns, constraints and every option keep their order.
     *
     * @param  array<string, array<string, mixed>> $tables
     * @return array<string, array<string, mixed>>
     */
    private static function comparable(array $tables): array
    {
        foreach ($tables as $name => $table) {
            if (!isset($table['create_sql']) || !str_contains($table['create_sql'], "\n  PRIMARY KEY")) {
                continue;
            }
            $lines = explode("\n", $table['create_sql']);
            $keys = array_keys(array_filter($lines, static fn (string $line): bool => preg_match('/^  (UNIQUE |FULLTEXT |SPATIAL )?KEY /', $line) === 1));
            $sorted = array_map(static fn (int $index): string => rtrim($lines[$index], ','), $keys);
            sort($sorted);
            foreach ($keys as $position => $index) {
                $lines[$index] = $sorted[$position].(str_ends_with($lines[$index], ',') ? ',' : '');
            }
            $tables[$name]['create_sql'] = implode("\n", $lines);
        }

        return $tables;
    }

    /** @param list<string> $lines */
    private static function diff(mixed $expected, mixed $actual, string $path, array &$lines): void
    {
        if (is_array($expected) && is_array($actual)) {
            $keys = array_unique([...array_keys($expected), ...array_keys($actual)]);
            foreach ($keys as $key) {
                $child = $path === '' ? (string) $key : $path.'.'.$key;
                if (!array_key_exists($key, $actual)) {
                    $lines[] = sprintf('%s: missing, expected %s', $child, self::dump($expected[$key]));
                } elseif (!array_key_exists($key, $expected)) {
                    $lines[] = sprintf('%s: unexpected %s', $child, self::dump($actual[$key]));
                } else {
                    self::diff($expected[$key], $actual[$key], $child, $lines);
                }
            }

            return;
        }

        if ($expected !== $actual) {
            $lines[] = sprintf('%s: expected %s actual %s', $path, self::dump($expected), self::dump($actual));
        }
    }

    private static function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private static function tableNames(Connection $db, string $driver): array
    {
        $rows = match ($driver) {
            'sqlite' => $db->select("select name from sqlite_master where type = 'table' and name not like 'sqlite\\_%' escape '\\' order by name"),
            'pgsql' => $db->select("select tablename as name from pg_tables where schemaname = 'public' order by tablename"),
            default => $db->select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' order by table_name"),
        };

        return array_values(array_filter(
            array_map(static fn (object $row): string => (string) ((array) $row)['name'], $rows),
            static fn (string $name): bool => $name !== 'migrations',
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(Connection $db, string $sql, array $bindings = []): array
    {
        return array_map(static fn (object $row): array => array_change_key_case((array) $row, CASE_LOWER), $db->select($sql, $bindings));
    }

    /** @return array<string, mixed> */
    private static function sqliteTable(Connection $db, string $table): array
    {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $sql = (string) self::rows($db, "select sql from sqlite_master where type = 'table' and name = ?", [$table])[0]['sql'];

        $columns = [];
        foreach (self::rows($db, 'pragma table_xinfo('.$quoted.')') as $column) {
            $columns[] = [
                'name' => $column['name'],
                'type' => $column['type'],
                'notnull' => (int) $column['notnull'],
                'default' => $column['dflt_value'],
                'pk' => (int) $column['pk'],
                'hidden' => (int) $column['hidden'],
            ];
        }

        $indexes = [];
        foreach (self::rows($db, 'pragma index_list('.$quoted.')') as $index) {
            $name = (string) $index['name'];
            $columnsOfIndex = [];
            foreach (self::rows($db, 'pragma index_xinfo("'.str_replace('"', '""', $name).'")') as $part) {
                if ((int) $part['key'] === 1) {
                    $columnsOfIndex[] = ['name' => $part['name'], 'desc' => (int) $part['desc'], 'collation' => $part['coll']];
                }
            }
            $indexes[$name] = [
                'unique' => (int) $index['unique'],
                'origin' => $index['origin'],
                'partial' => (int) $index['partial'],
                'columns' => $columnsOfIndex,
                'sql' => self::rows($db, 'select sql from sqlite_master where type = ? and name = ?', ['index', $name])[0]['sql'] ?? null,
            ];
        }
        ksort($indexes);

        $foreignKeys = [];
        foreach (self::rows($db, 'pragma foreign_key_list('.$quoted.')') as $key) {
            $foreignKeys[(int) $key['id'].'.'.(int) $key['seq']] = [
                'from' => $key['from'],
                'table' => $key['table'],
                'to' => $key['to'],
                'on_update' => $key['on_update'],
                'on_delete' => $key['on_delete'],
                'match' => $key['match'],
            ];
        }

        return ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys, 'create_sql' => $sql];
    }

    /** @return array<string, mixed> */
    private static function pgsqlTable(Connection $db, string $table): array
    {
        $columns = [];
        foreach (self::rows($db, <<<'SQL'
            select a.attname as name, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull as not_null,
                   pg_get_expr(d.adbin, d.adrelid) as "default", a.attidentity as identity, a.attgenerated as generated,
                   co.collname as collation
            from pg_attribute a
            join pg_class c on c.oid = a.attrelid
            join pg_namespace n on n.oid = c.relnamespace
            left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
            left join pg_collation co on co.oid = a.attcollation
            where n.nspname = 'public' and c.relname = ? and a.attnum > 0 and not a.attisdropped
            order by a.attnum
            SQL, [$table]) as $column) {
            $columns[] = $column;
        }

        $indexes = [];
        foreach (self::rows($db, "select indexname, indexdef from pg_indexes where schemaname = 'public' and tablename = ? order by indexname", [$table]) as $index) {
            $indexes[(string) $index['indexname']] = $index['indexdef'];
        }

        $constraints = [];
        foreach (self::rows($db, <<<'SQL'
            select con.conname as name, con.contype as type, pg_get_constraintdef(con.oid) as definition,
                   con.condeferrable as deferrable, con.condeferred as deferred, con.convalidated as validated
            from pg_constraint con
            join pg_class c on c.oid = con.conrelid
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relname = ?
            order by con.conname
            SQL, [$table]) as $constraint) {
            $name = (string) $constraint['name'];
            unset($constraint['name']);
            $constraints[$name] = $constraint;
        }

        return ['columns' => $columns, 'indexes' => $indexes, 'constraints' => $constraints];
    }

    /** @return array<string, mixed> */
    private static function mysqlTable(Connection $db, string $table): array
    {
        $columns = [];
        foreach (self::rows($db, <<<'SQL'
            select column_name as name, column_type as type, is_nullable as nullable, column_default as "default",
                   character_set_name as charset, collation_name as collation, extra, generation_expression as generation
            from information_schema.columns
            where table_schema = database() and table_name = ?
            order by ordinal_position
            SQL, [$table]) as $column) {
            $columns[] = $column;
        }

        $indexes = [];
        foreach (self::rows($db, <<<'SQL'
            select index_name as name, seq_in_index as seq, column_name as column_name, sub_part, collation as direction,
                   non_unique, index_type, expression, is_visible
            from information_schema.statistics
            where table_schema = database() and table_name = ?
            order by index_name, seq_in_index
            SQL, [$table]) as $part) {
            $name = (string) $part['name'];
            $indexes[$name]['unique'] = (int) $part['non_unique'] === 0;
            $indexes[$name]['type'] = $part['index_type'];
            $indexes[$name]['visible'] = $part['is_visible'];
            $indexes[$name]['parts'][] = [
                'column' => $part['column_name'],
                'expression' => $part['expression'],
                'sub_part' => $part['sub_part'],
                'direction' => $part['direction'],
            ];
        }

        $constraints = [];
        foreach (self::rows($db, <<<'SQL'
            select rc.constraint_name as name, kcu.column_name as column_name, kcu.referenced_table_name as ref_table,
                   kcu.referenced_column_name as ref_column, rc.update_rule, rc.delete_rule
            from information_schema.referential_constraints rc
            join information_schema.key_column_usage kcu
              on kcu.constraint_schema = rc.constraint_schema and kcu.constraint_name = rc.constraint_name and kcu.table_name = rc.table_name
            where rc.constraint_schema = database() and rc.table_name = ?
            order by rc.constraint_name, kcu.ordinal_position
            SQL, [$table]) as $part) {
            $name = (string) $part['name'];
            $constraints[$name]['type'] = 'foreign key';
            $constraints[$name]['ref_table'] = $part['ref_table'];
            $constraints[$name]['update'] = $part['update_rule'];
            $constraints[$name]['delete'] = $part['delete_rule'];
            $constraints[$name]['columns'][] = [$part['column_name'], $part['ref_column']];
        }

        foreach (self::rows($db, <<<'SQL'
            select tc.constraint_name as name, cc.check_clause as clause, tc.enforced
            from information_schema.table_constraints tc
            join information_schema.check_constraints cc
              on cc.constraint_schema = tc.constraint_schema and cc.constraint_name = tc.constraint_name
            where tc.table_schema = database() and tc.table_name = ? and tc.constraint_type = 'CHECK'
            order by tc.constraint_name
            SQL, [$table]) as $check) {
            $constraints[(string) $check['name']] = ['type' => 'check', 'clause' => $check['clause'], 'enforced' => $check['enforced']];
        }

        $create = (array) $db->selectOne('show create table `'.str_replace('`', '``', $table).'`');
        $createSql = (string) ($create['Create Table'] ?? array_values($create)[1]);

        return [
            'columns' => $columns,
            'indexes' => $indexes,
            'constraints' => $constraints,
            // The per-run AUTO_INCREMENT counter and the server's file-per-table setting (a TABLESPACE
            // clause appears only when it is off) are not part of the schema.
            'create_sql' => (string) preg_replace(['/ AUTO_INCREMENT=\d+/', '/ \/\*!50100 TABLESPACE `[^`]+` \*\//'], '', $createSql),
        ];
    }
}
