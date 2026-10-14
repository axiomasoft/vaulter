<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;

/**
 * I3 — foreign keys point along the dependency direction. Builds `fk-map.json` from the schema identities (the same
 * foreign keys on pgsql and mysql; sqlite does not enforce the head FKs) and judges each: `ok` when the owner of the
 * referenced table is the owner of the referencing one or one of its target requirements, else `reverse`.
 *
 * @phpstan-import-type Violation from Scan
 *
 * @phpstan-type Fk array{name: string, from_table: string, columns: list<string>, to_table: string, to_columns: list<string>, on_delete: string, on_update: string, deferrable: bool, head: bool, from_owner: string, to_owner: string, verdict: string, engines: list<string>}
 */
final class ForeignKeys
{
    public const INVARIANT = 'I3';

    public const FIXTURE = 'tests/Repo/Arch/Fixtures/fk-map.json';

    public const SCHEMA = 'tests/Repo/Arch/Fixtures/identities/schema.json';

    public const BASE = '35718fd2';

    /**
     * @return array{version: int, base: string, fks: list<Fk>}
     */
    public static function map(Repo $repo): array
    {
        $items = $repo->readJson(self::SCHEMA)['items'] ?? null;
        if (!is_array($items)) {
            throw new InventoryError(self::SCHEMA.' has no "items" list');
        }

        /** @var list<array<string, mixed>> $items */
        return self::build($items, $repo->packages());
    }

    /**
     * @param  list<array<string, mixed>>                       $items    the schema identities
     * @param  array<string, array<string, mixed>>              $packages the packages fixture
     * @return array{version: int, base: string, fks: list<Fk>}
     */
    public static function build(array $items, array $packages): array
    {
        $tables = [];
        foreach ($items as $item) {
            if (str_starts_with((string) $item['id'], 'table:')) {
                $tables[substr((string) $item['id'], 6)] = (string) $item['owner'];
            }
        }

        $fks = [];
        foreach ($items as $item) {
            if (!str_starts_with((string) $item['id'], 'fk:')) {
                continue;
            }
            $fks[] = self::fk($item, $tables, $packages);
        }
        usort($fks, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['version' => 1, 'base' => self::BASE, 'fks' => $fks];
    }

    /**
     * @param  array{fks: list<Fk>} $map
     * @return list<Violation>
     */
    public static function found(array $map): array
    {
        $found = [];
        foreach ($map['fks'] as $fk) {
            if ($fk['verdict'] !== 'reverse') {
                continue;
            }
            $found[] = [
                'invariant' => self::INVARIANT,
                'view' => 'target',
                'file' => self::FIXTURE,
                'symbol' => $fk['from_table'],
                'kind' => 'fk',
                'target' => $fk['name'],
                'target_owner' => $fk['to_owner'],
                'count' => 1,
                'category' => 'reverse-fk',
            ];
        }

        return Scan::merge($found);
    }

    /**
     * @param  array<string, mixed>                $item
     * @param  array<string, string>               $tables
     * @param  array<string, array<string, mixed>> $packages
     * @return Fk
     */
    private static function fk(array $item, array $tables, array $packages): array
    {
        $id = (string) $item['id'];
        $from = (string) ($item['table'] ?? '');
        $engines = is_array($item['engines'] ?? null) ? $item['engines'] : [];
        $shapes = [];
        foreach ($engines as $engine => $data) {
            $shapes[json_encode($data)][] = (string) $engine;
        }
        if (count($shapes) !== 1) {
            throw new InventoryError("{$id}: the engines disagree about the foreign key");
        }
        /** @var array<string, mixed> $data */
        $data = reset($engines);
        $engineNames = array_map('strval', array_keys($engines));
        sort($engineNames, SORT_STRING);

        $to = (string) ($data['ref_table'] ?? '');
        $name = substr($id, strlen('fk:'.$from.'.'));
        foreach ([$from, $to] as $table) {
            if (!isset($tables[$table])) {
                throw new InventoryError("{$id}: table {$table} has no owner in the schema identities");
            }
        }
        $fromOwner = $tables[$from];
        $toOwner = $tables[$to];
        $ok = $fromOwner === $toOwner || in_array($toOwner, ScanContext::closureOf($packages, $fromOwner), true);

        return [
            'name' => $name,
            'from_table' => $from,
            'columns' => array_values(array_map('strval', (array) ($data['columns'] ?? []))),
            'to_table' => $to,
            'to_columns' => array_values(array_map('strval', (array) ($data['ref_columns'] ?? []))),
            'on_delete' => strtolower((string) ($data['on_delete'] ?? 'no action')),
            'on_update' => strtolower((string) ($data['on_update'] ?? 'no action')),
            'deferrable' => ($data['deferrable'] ?? false) === true,
            'head' => str_ends_with($name, '_head_fk'),
            'from_owner' => $fromOwner,
            'to_owner' => $toOwner,
            'verdict' => $ok ? 'ok' : 'reverse',
            'engines' => $engineNames,
        ];
    }
}
