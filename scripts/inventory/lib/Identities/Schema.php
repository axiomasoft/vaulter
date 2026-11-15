<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Identities;

use Vaulter\Inventory\Identities;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/Identities.php';

/**
 * identities/schema.json from the committed catalog snapshots (packages/core/tests/Fixtures/Schema, no database).
 *
 * Details come from the canonical preset on every engine. All 48 presets of an engine are then compared: the set of
 * logical tables, columns, indexes, constraints and foreign keys must be the same (the `custom_` table prefix is
 * folded into `v_`), and what varies with the id strategy, the host key type or tenancy is recorded as `variants`.
 * Anything else is drift (exit 1). SQLite does not name foreign keys, so they are taken from pgsql and mysql, which
 * must agree (a disagreement is recorded as `engine_diff`, a finding, never smoothed over).
 *
 * @phpstan-import-type Built from Identities
 * @phpstan-import-type Problem from Identities
 */
final class Schema
{
    public const FIXTURES = 'packages/core/tests/Fixtures/Schema';

    public const CANONICAL = 'bigint.string.v.off';

    public const ENGINES = ['pgsql', 'mysql', 'sqlite'];

    public const AXES = ['ids', 'host_keys', 'table_prefix', 'tenancy'];

    /** Axes whose variation is expected; a record that follows another axis is drift. */
    private const ALLOWED_AXES = ['ids', 'host_keys', 'tenancy'];

    /** A module's create-migrations live in its own package, webhooks' in core until the webhooks package exists. */
    private const MIGRATIONS = 'packages/core/database/migrations';

    private const MIGRATION_DIRS = 'packages/*/database/migrations';

    private const FK_FIELDS = ['columns', 'ref_table', 'ref_columns', 'on_delete', 'on_update'];

    /** @return Built */
    public static function build(Repo $repo): array
    {
        $problems = [];
        $presets = self::presets($repo);
        $migrations = self::migrations($repo);

        /** @var array<string, array<string, array<string, array<string, mixed>>>> $entities engine => entity id => preset => record */
        $entities = [];
        /** @var array<string, list<string>> $columnOrder table => column names (pgsql, canonical preset) */
        $columnOrder = [];
        /** @var array<string, array<string, list<array<string, mixed>>>> $sqliteFks table => preset => unnamed foreign keys (canonical only) */
        $sqliteFks = [];
        $originalDiff = [];

        foreach (self::ENGINES as $engine) {
            foreach ($presets as $preset) {
                $expected = self::snapshot($repo, 'expected', $engine, $preset);
                foreach ($expected['tables'] as $table => $definition) {
                    self::collect($entities, $engine, $preset, $table, $definition);
                    if ($preset === self::CANONICAL) {
                        if ($engine === 'pgsql') {
                            $columnOrder[$table] = array_column(self::list($definition['columns']), 'name');
                        }
                        if ($engine === 'sqlite') {
                            $sqliteFks[$table][$preset] = self::sqliteForeignKeys(self::map($definition['foreign_keys'] ?? []));
                        }
                    }
                }
                $original = self::snapshot($repo, 'original', $engine, $preset);
                self::compareOriginal($originalDiff, $engine, $preset, $expected['tables'], $original['tables']);
            }
        }

        $tables = array_keys($columnOrder);
        sort($tables, SORT_STRING);
        $migrated = array_merge([], ...array_column($migrations, 'tables'));
        $unmigrated = array_diff($tables, $migrated);
        $unknown = array_diff($migrated, $tables);
        foreach ($unmigrated as $table) {
            $problems[] = ['code' => 'no-migration', 'message' => "schema: table {$table} has no create_{$table}_table migration", 'exit' => 1];
        }
        foreach ($unknown as $table) {
            $problems[] = ['code' => 'no-table', 'message' => "schema: migration of {$table} creates a table the snapshots do not have", 'exit' => 1];
        }
        $migrationOf = [];
        $migrationDir = [];
        foreach ($migrations as $basename => $migration) {
            foreach ($migration['tables'] as $table) {
                $migrationOf[$table] = $basename;
                $migrationDir[$table] = $migration['dir'];
            }
        }

        $items = [];
        foreach ($tables as $table) {
            $basename = $migrationOf[$table] ?? null;
            $source = ['file' => $basename === null ? self::MIGRATIONS : $migrationDir[$table].'/'.$basename, 'symbol' => $table];
            $item = ['id' => "table:{$table}", 'source' => $source, 'logical' => substr($table, 2), 'columns' => $columnOrder[$table], 'migration' => $basename];
            if (isset($originalDiff[$table])) {
                $item['original_diff'] = $originalDiff[$table];
            }
            $sqlite = $sqliteFks[$table][self::CANONICAL] ?? [];
            $pg = self::foreignKeyShapes($entities['pgsql'] ?? [], $table);
            $missing = self::shapesMinus($pg, $sqlite);
            $extra = self::shapesMinus($sqlite, $pg);
            if ($missing !== [] || $extra !== []) {
                $item['sqlite_fk_diff'] = array_filter(['missing_in_sqlite' => $missing, 'only_in_sqlite' => $extra]);
            }
            $items[] = $item;
        }
        foreach ($migrations as $basename => $migration) {
            $items[] = ['id' => 'migration:'.$basename, '_owner_key' => 'table:'.$migration['tables'][0], 'source' => ['file' => $migration['dir'].'/'.$basename, 'symbol' => '<file>'], 'tables' => $migration['tables']];
        }

        // entity id (without engine) => engine => preset => record
        $byId = [];
        foreach ($entities as $engine => $perId) {
            foreach ($perId as $id => $perPreset) {
                $byId[$id][$engine] = $perPreset;
            }
        }
        ksort($byId, SORT_STRING);
        foreach ($byId as $id => $perEngine) {
            if (str_starts_with($id, 'table-exists:')) {
                self::presence($id, $perEngine, $presets, $problems);

                continue;
            }
            [$kind, $rest] = explode(':', $id, 2);
            $table = explode('.', $rest, 2)[0];
            $source = ['file' => isset($migrationOf[$table]) ? $migrationDir[$table].'/'.$migrationOf[$table] : self::MIGRATIONS, 'symbol' => $table];
            $item = ['id' => $id, '_owner_key' => 'table:'.$table, 'source' => $source, 'table' => $table, 'engines' => [], 'variants' => []];
            foreach (self::ENGINES as $engine) {
                if (!isset($perEngine[$engine])) {
                    continue;
                }
                $resolved = self::resolve($id, $engine, $perEngine[$engine], $presets, $problems);
                if ($resolved === null) {
                    continue;
                }
                $item['engines'][$engine] = $resolved['record'];
                if ($resolved['variants'] !== null) {
                    $item['variants'][$engine] = $resolved['variants'];
                }
            }
            if ($item['variants'] === []) {
                unset($item['variants']);
            }
            if ($kind === 'fk') {
                $diff = self::foreignKeyDiff($item['engines']);
                if ($diff !== []) {
                    $item['engine_diff'] = $diff;
                }
            }
            $items[] = $item;
        }

        return Identities::assemble('schema', $items, IdentityOwners::load($repo, 'schema'), [
            'axes' => self::AXES,
            'engines' => self::ENGINES,
            'presets' => count($presets),
            'canonical_preset' => self::CANONICAL,
        ], $problems);
    }

    /** @return list<string> every preset name, `{ids}.{host_keys}.{table_prefix}.{tenancy}` of snapshot-configs.json, sorted */
    private static function presets(Repo $repo): array
    {
        $axes = $repo->readJson(self::FIXTURES.'/snapshot-configs.json')['axes'] ?? null;
        if (!is_array($axes)) {
            throw new InventoryError(self::FIXTURES.'/snapshot-configs.json has no "axes"');
        }
        $names = [];
        foreach (self::values($axes['ids'] ?? []) as $ids) {
            foreach (self::values($axes['host_keys'] ?? []) as $hostKeys) {
                foreach (self::values($axes['table_prefix'] ?? []) as $prefix) {
                    foreach (self::values($axes['tenancy'] ?? []) as $tenancy) {
                        $names[] = "{$ids}.{$hostKeys}.{$prefix}.{$tenancy}";
                    }
                }
            }
        }
        sort($names, SORT_STRING);
        if (count($names) !== 48 || !in_array(self::CANONICAL, $names, true)) {
            throw new InventoryError('snapshot-configs.json: 48 presets and the canonical '.self::CANONICAL.' are expected, found '.count($names));
        }
        foreach (['expected', 'original'] as $kind) {
            foreach (self::ENGINES as $engine) {
                $files = array_map(static fn (string $p): string => basename($p, '.json'), glob($repo->path(self::FIXTURES."/{$kind}/{$engine}").'/*.json') ?: []);
                sort($files, SORT_STRING);
                if ($files !== $names) {
                    throw new InventoryError("{$kind}/{$engine} does not hold exactly the 48 presets of snapshot-configs.json");
                }
            }
        }

        return $names;
    }

    /** @return list<string> an axis is a list of values or an object whose keys are the preset segments */
    private static function values(mixed $axis): array
    {
        if (!is_array($axis)) {
            return [];
        }

        return array_map(strval(...), array_is_list($axis) ? $axis : array_keys($axis));
    }

    /**
     * @return array{tables: array<string, array<string, mixed>>}
     */
    private static function snapshot(Repo $repo, string $kind, string $engine, string $preset): array
    {
        $data = $repo->readJson(self::FIXTURES."/{$kind}/{$engine}/{$preset}.json");
        $tables = $data['tables'] ?? null;
        if (!is_array($tables)) {
            throw new InventoryError("{$kind}/{$engine}/{$preset}.json has no tables");
        }
        $folded = [];
        foreach ($tables as $table => $definition) {
            if (is_array($definition)) {
                /** @var array<string, mixed> $normalized */
                $normalized = self::fold($definition);
                $folded[self::fold((string) $table)] = $normalized;
            }
        }

        /** @var array<string, array<string, mixed>> $folded */
        return ['tables' => $folded];
    }

    /** Folds the `custom_` table prefix into `v_` everywhere (names, definitions, defaults). */
    private static function fold(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('/\bcustom_/', 'v_', $value) ?? $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? self::fold($key) : $key] = self::fold($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        /** @var list<array<string, mixed>> $list */
        $list = is_array($value) ? array_values($value) : [];

        return $list;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function map(mixed $value): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = is_array($value) ? $value : [];

        return $map;
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $entities
     * @param array<string, mixed>                                              $definition
     */
    private static function collect(array &$entities, string $engine, string $preset, string $table, array $definition): void
    {
        $entities[$engine]["table-exists:{$table}"][$preset] = ['present' => true];
        foreach (self::list($definition['columns'] ?? []) as $column) {
            $entities[$engine]["column:{$table}.{$column['name']}"][$preset] = self::column($engine, $column);
        }
        foreach (self::map($definition['indexes'] ?? []) as $name => $index) {
            if (str_starts_with((string) $name, 'sqlite_autoindex_')) {
                continue; // engine-internal, named after the primary key type of the preset
            }
            $entities[$engine]["index:{$table}.{$name}"][$preset] = self::index($engine, $index);
        }
        foreach (self::map($definition['constraints'] ?? []) as $name => $constraint) {
            $record = self::constraint($engine, $constraint);
            $kind = $record['kind'] === 'foreign key' ? 'fk' : 'constraint';
            $entities[$engine]["{$kind}:{$table}.{$name}"][$preset] = $record;
        }
    }

    /**
     * @param  array<string, mixed> $column
     * @return array<string, mixed>
     */
    private static function column(string $engine, array $column): array
    {
        return match ($engine) {
            'pgsql' => array_filter([
                'type' => $column['type'],
                'nullable' => !($column['not_null'] ?? false),
                'default' => $column['default'] ?? null,
                'identity' => ($column['identity'] ?? '') === '' ? null : $column['identity'],
                'generated' => ($column['generated'] ?? '') === '' ? null : $column['generated'],
                'collation' => $column['collation'] ?? null,
            ], static fn (mixed $v, string $k): bool => $v !== null || $k === 'default', ARRAY_FILTER_USE_BOTH),
            'mysql' => array_filter([
                'type' => $column['type'],
                'nullable' => ($column['nullable'] ?? 'YES') === 'YES',
                'default' => $column['default'] ?? null,
                'extra' => ($column['extra'] ?? '') === '' ? null : $column['extra'],
                'generation' => ($column['generation'] ?? '') === '' ? null : $column['generation'],
                'charset' => $column['charset'] ?? null,
                'collation' => $column['collation'] ?? null,
            ], static fn (mixed $v, string $k): bool => $v !== null || $k === 'default', ARRAY_FILTER_USE_BOTH),
            default => array_filter([
                'type' => $column['type'],
                'nullable' => ($column['notnull'] ?? 0) === 0,
                'default' => $column['default'] ?? null,
                'primary_key' => ($column['pk'] ?? 0) > 0 ? $column['pk'] : null,
                'hidden' => ($column['hidden'] ?? 0) > 0 ? $column['hidden'] : null,
            ], static fn (mixed $v, string $k): bool => $v !== null || $k === 'default', ARRAY_FILTER_USE_BOTH),
        };
    }

    /**
     * @param  array<string, mixed>|string $index
     * @return array<string, mixed>
     */
    private static function index(string $engine, array|string $index): array
    {
        if ($engine === 'pgsql') {
            $definition = (string) $index;
            if (preg_match('/^CREATE (UNIQUE )?INDEX \S+ ON (?:ONLY )?(?:public\.)?\S+ USING (\w+) (.*)$/s', $definition, $m) !== 1) {
                return ['definition' => $definition];
            }
            $parts = explode(' WHERE ', $m[3], 2);

            return array_filter(['unique' => $m[1] !== '', 'method' => $m[2], 'definition' => $parts[0], 'predicate' => $parts[1] ?? null], static fn (mixed $v): bool => $v !== null);
        }
        if (is_string($index)) {
            return ['definition' => $index];
        }
        if ($engine === 'mysql') {
            $columns = [];
            foreach (self::list($index['parts'] ?? []) as $part) {
                $text = (string) ($part['column'] ?? '('.($part['expression'] ?? '?').')');
                $columns[] = $text.(isset($part['sub_part']) ? '('.$part['sub_part'].')' : '').(($part['direction'] ?? 'A') === 'D' ? ' DESC' : '');
            }

            return array_filter(['unique' => (bool) ($index['unique'] ?? false), 'method' => $index['type'] ?? null, 'columns' => $columns, 'invisible' => ($index['visible'] ?? 'YES') === 'YES' ? null : true], static fn (mixed $v): bool => $v !== null);
        }
        $columns = [];
        foreach (self::list($index['columns'] ?? []) as $column) {
            $columns[] = ($column['name'] ?? '(expression)').(($column['desc'] ?? 0) === 1 ? ' DESC' : '');
        }
        $predicate = null;
        if (($index['partial'] ?? 0) === 1 && preg_match('/\swhere\s(.*)$/is', (string) ($index['sql'] ?? ''), $m) === 1) {
            $predicate = $m[1];
        }

        return array_filter(['unique' => ($index['unique'] ?? 0) === 1, 'origin' => $index['origin'] ?? null, 'columns' => $columns, 'predicate' => $predicate], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed> $constraint
     * @return array<string, mixed> always has `kind`
     */
    private static function constraint(string $engine, array $constraint): array
    {
        if ($engine === 'mysql') {
            if (($constraint['type'] ?? '') === 'foreign key') {
                $pairs = is_array($constraint['columns'] ?? null) ? array_values($constraint['columns']) : [];

                return [
                    'kind' => 'foreign key',
                    'columns' => array_map(static fn (mixed $pair): string => (string) (is_array($pair) ? $pair[0] : ''), $pairs),
                    'ref_table' => $constraint['ref_table'],
                    'ref_columns' => array_map(static fn (mixed $pair): string => (string) (is_array($pair) ? $pair[1] : ''), $pairs),
                    'on_delete' => $constraint['delete'] ?? 'NO ACTION',
                    'on_update' => $constraint['update'] ?? 'NO ACTION',
                ];
            }

            return array_filter(['kind' => (string) ($constraint['type'] ?? 'unknown'), 'definition' => $constraint['clause'] ?? null, 'enforced' => ($constraint['enforced'] ?? 'YES') === 'YES' ? null : false], static fn (mixed $v): bool => $v !== null);
        }

        $type = (string) ($constraint['type'] ?? '');
        $definition = (string) ($constraint['definition'] ?? '');
        if ($type === 'f') {
            if (preg_match('/^FOREIGN KEY \(([^)]*)\) REFERENCES (?:public\.)?(\S+?)\(([^)]*)\)(.*)$/s', $definition, $m) !== 1) {
                return ['kind' => 'foreign key', 'definition' => $definition];
            }

            return array_filter([
                'kind' => 'foreign key',
                'columns' => self::names($m[1]),
                'ref_table' => $m[2],
                'ref_columns' => self::names($m[3]),
                'on_delete' => preg_match('/ON DELETE (CASCADE|RESTRICT|SET NULL|SET DEFAULT|NO ACTION)/', $m[4], $d) === 1 ? $d[1] : 'NO ACTION',
                'on_update' => preg_match('/ON UPDATE (CASCADE|RESTRICT|SET NULL|SET DEFAULT|NO ACTION)/', $m[4], $u) === 1 ? $u[1] : 'NO ACTION',
                'deferrable' => ($constraint['deferrable'] ?? false) === true ? true : null,
                'deferred' => ($constraint['deferred'] ?? false) === true ? true : null,
                'not_validated' => ($constraint['validated'] ?? true) === false ? true : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return array_filter([
            'kind' => match ($type) {
                'p' => 'primary key', 'u' => 'unique', 'c' => 'check', default => "pg:{$type}",
            },
            'definition' => $definition,
            'deferrable' => ($constraint['deferrable'] ?? false) === true ? true : null,
            'not_validated' => ($constraint['validated'] ?? true) === false ? true : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return list<string> */
    private static function names(string $list): array
    {
        return array_map(static fn (string $name): string => trim($name, ' "`'), explode(',', $list));
    }

    /**
     * Unnamed SQLite foreign keys ("<id>.<seq>" rows) as the shapes of the named ones.
     *
     * @param  array<string, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sqliteForeignKeys(array $rows): array
    {
        $groups = [];
        foreach ($rows as $key => $row) {
            [$id, $seq] = array_pad(explode('.', (string) $key, 2), 2, '0');
            $groups[(int) $id][(int) $seq] = $row;
        }
        $shapes = [];
        foreach ($groups as $parts) {
            ksort($parts);
            $first = reset($parts);
            $shapes[] = [
                'columns' => array_values(array_map(static fn (array $p): string => (string) $p['from'], $parts)),
                'ref_table' => (string) $first['table'],
                'ref_columns' => array_values(array_map(static fn (array $p): string => (string) $p['to'], $parts)),
                'on_delete' => (string) $first['on_delete'],
                'on_update' => (string) $first['on_update'],
            ];
        }

        return self::sortShapes($shapes);
    }

    /**
     * The foreign key shapes of one table at the canonical preset on pgsql, as list.
     *
     * @param  array<string, array<string, array<string, mixed>>> $entities of one engine: id => preset => record
     * @return list<array<string, mixed>>
     */
    private static function foreignKeyShapes(array $entities, string $table): array
    {
        $shapes = [];
        foreach ($entities as $id => $perPreset) {
            if (str_starts_with($id, "fk:{$table}.") && isset($perPreset[self::CANONICAL])) {
                $shapes[] = array_intersect_key($perPreset[self::CANONICAL], array_flip(self::FK_FIELDS));
            }
        }

        return self::sortShapes($shapes);
    }

    /**
     * @param  list<array<string, mixed>> $from
     * @param  list<array<string, mixed>> $without
     * @return list<array<string, mixed>>
     */
    private static function shapesMinus(array $from, array $without): array
    {
        $drop = array_map(static fn (array $s): string => json_encode($s, JSON_THROW_ON_ERROR), $without);

        return array_values(array_filter($from, static fn (array $s): bool => !in_array(json_encode($s, JSON_THROW_ON_ERROR), $drop, true)));
    }

    /**
     * @param  list<array<string, mixed>> $shapes
     * @return list<array<string, mixed>>
     */
    private static function sortShapes(array $shapes): array
    {
        usort($shapes, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return $shapes;
    }

    /**
     * @param  array<string, array<string, mixed>> $engines the canonical record of a foreign key per engine
     * @return array<string, array<string, mixed>> field => engine => value, only for fields that differ
     */
    private static function foreignKeyDiff(array $engines): array
    {
        $diff = [];
        if (count($engines) < 2) {
            $diff['present'] = array_map(static fn (): bool => true, $engines);
        }
        foreach (self::FK_FIELDS as $field) {
            $values = [];
            foreach ($engines as $engine => $record) {
                $values[$engine] = $record[$field] ?? null;
            }
            if (count(array_unique(array_map(static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR), $values))) > 1) {
                $diff[$field] = $values;
            }
        }

        return $diff;
    }

    /**
     * A table must exist in every preset of every engine.
     *
     * @param array<string, array<string, array<string, mixed>>> $perEngine
     * @param list<string>                                       $presets
     * @param list<Problem>                                      $problems
     */
    private static function presence(string $id, array $perEngine, array $presets, array &$problems): void
    {
        foreach ($perEngine as $engine => $perPreset) {
            $missing = array_values(array_diff($presets, array_keys($perPreset)));
            if ($missing !== []) {
                $problems[] = ['code' => 'unexpected-table-set', 'message' => sprintf('schema: %s %s is missing in %d presets (%s%s)', $engine, substr($id, 13), count($missing), implode(', ', array_slice($missing, 0, 3)), count($missing) > 3 ? ', …' : ''), 'exit' => 1];
            }
        }
    }

    /**
     * What an entity looks like on one engine: the canonical record, and — if it depends on the preset — the axes it
     * follows with the fields that differ per axis combination.
     *
     * @param  array<string, array<string, mixed>>                                       $perPreset
     * @param  list<string>                                                              $presets
     * @param  list<Problem>                                                             $problems
     * @return array{record: array<string, mixed>, variants: ?array<string, mixed>}|null
     */
    private static function resolve(string $id, string $engine, array $perPreset, array $presets, array &$problems): ?array
    {
        if (count($perPreset) !== count($presets)) {
            $missing = array_values(array_diff($presets, array_keys($perPreset)));
            $problems[] = ['code' => 'unexpected-difference', 'message' => "schema: {$engine} {$id} exists in ".count($perPreset).' of '.count($presets).' presets (missing: '.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? ', …' : '').')', 'exit' => 1];
        }
        $canonical = $perPreset[self::CANONICAL] ?? null;
        if ($canonical === null) {
            return null;
        }

        $encoded = array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR), $perPreset);
        if (count(array_unique($encoded)) === 1) {
            return ['record' => $canonical, 'variants' => null];
        }

        $axes = self::minimalAxes($perPreset);
        if ($axes === null) {
            $problems[] = ['code' => 'unexpected-difference', 'message' => "schema: {$engine} {$id} varies between presets in a way no combination of axes explains", 'exit' => 1];

            return ['record' => $canonical, 'variants' => null];
        }
        $outside = array_values(array_diff($axes, self::ALLOWED_AXES));
        if ($outside !== []) {
            $problems[] = ['code' => 'unexpected-difference', 'message' => "schema: {$engine} {$id} depends on the axis ".implode(', ', $outside).' (allowed: '.implode(', ', self::ALLOWED_AXES).')', 'exit' => 1];
        }

        $by = [];
        foreach ($perPreset as $preset => $record) {
            $segments = explode('.', $preset);
            $key = implode(',', array_map(static fn (string $axis): string => $axis.'='.$segments[array_search($axis, self::AXES, true)], $axes));
            if ($record !== $canonical && !isset($by[$key])) {
                $by[$key] = self::changed($canonical, $record);
            }
        }
        ksort($by, SORT_STRING);

        return ['record' => $canonical, 'variants' => ['axes' => $axes, 'by' => $by]];
    }

    /**
     * The smallest set of axes (fewest first, in axis order) whose values determine the record.
     *
     * @param  array<string, array<string, mixed>> $perPreset
     * @return list<string>|null
     */
    private static function minimalAxes(array $perPreset): ?array
    {
        $count = count(self::AXES);
        for ($size = 1; $size <= $count; $size++) {
            foreach (self::combinations($count, $size) as $subset) {
                $groups = [];
                $ok = true;
                foreach ($perPreset as $preset => $record) {
                    $segments = explode('.', $preset);
                    $key = implode('|', array_map(static fn (int $i): string => $segments[$i], $subset));
                    $encoded = json_encode($record, JSON_THROW_ON_ERROR);
                    if (($groups[$key] ?? $encoded) !== $encoded) {
                        $ok = false;

                        break;
                    }
                    $groups[$key] = $encoded;
                }
                if ($ok) {
                    return array_map(static fn (int $i): string => self::AXES[$i], $subset);
                }
            }
        }

        return null;
    }

    /** @return list<list<int>> */
    private static function combinations(int $n, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $result = [];
        for ($i = 0; $i <= $n - $k; $i++) {
            foreach (self::combinations($n - $i - 1, $k - 1) as $rest) {
                $result[] = [$i, ...array_map(static fn (int $r): int => $r + $i + 1, $rest)];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed> $canonical
     * @param  array<string, mixed> $record
     * @return array<string, mixed> the fields whose value differs from the canonical record
     */
    private static function changed(array $canonical, array $record): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($canonical), ...array_keys($record)]) as $field) {
            if (($canonical[$field] ?? null) !== ($record[$field] ?? null)) {
                $changed[$field] = $record[$field] ?? null;
            }
        }

        return $changed;
    }

    /**
     * Records where the earlier snapshot has another table or column set than the current one: findings.
     *
     * @param array<string, array<string, mixed>> $diff     table => engine => differences (filled)
     * @param array<string, array<string, mixed>> $expected
     * @param array<string, array<string, mixed>> $original
     */
    private static function compareOriginal(array &$diff, string $engine, string $preset, array $expected, array $original): void
    {
        foreach (array_unique([...array_keys($expected), ...array_keys($original)]) as $table) {
            if (!isset($original[$table])) {
                $diff[$table][$engine]['absent_in_original'][] = $preset;

                continue;
            }
            if (!isset($expected[$table])) {
                $diff[$table][$engine]['absent_in_expected'][] = $preset;

                continue;
            }
            $now = array_column(self::list($expected[$table]['columns'] ?? []), 'name');
            $then = array_column(self::list($original[$table]['columns'] ?? []), 'name');
            if ($now !== $then) {
                $diff[$table][$engine]['columns'][$preset] = ['added' => array_values(array_diff($now, $then)), 'removed' => array_values(array_diff($then, $now))];
            }
        }
    }

    /**
     * basename => {tables, dir}, statically from `…_create_v_<t>_table.php` of every package.
     *
     * @return array<string, array{tables: list<string>, dir: string}>
     */
    private static function migrations(Repo $repo): array
    {
        $migrations = [];
        $files = glob($repo->path(self::MIGRATION_DIRS).'/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $basename = basename($path);
            if (preg_match('/_create_(v_[a-z0-9_]+)_table\.php$/', $basename, $m) === 1) {
                $migrations[$basename] = ['tables' => [$m[1]], 'dir' => substr(dirname($path), strlen($repo->path('')))];
            }
        }

        return $migrations;
    }
}
