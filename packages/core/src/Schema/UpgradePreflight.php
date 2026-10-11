<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Vaulter\Configuration\DatabaseConfig;

/**
 * Finds 0.2 data the 0.3 schema upgrade cannot carry over. Shared by the upgrade
 * migration and the read-only inspect command so both report the same problems.
 */
final readonly class UpgradePreflight
{
    public function __construct(private DatabaseConfig $tables) {}

    /** @return list<string> */
    public function problems(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable($this->tables->legacyTable('storages')) || $schema->hasTable($this->tables->table('drives'))) {
            return [];
        }

        $problems = [];
        foreach (['nodes', 'storages', 'files'] as $entity) {
            $table = $this->tables->legacyTable($entity);
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'deleted_at')) {
                $count = $db->table($table)->whereNotNull('deleted_at')->count();
                if ($count > 0) {
                    $names = $schema->hasColumn($table, 'name') ? $db->table($table)->whereNotNull('deleted_at')->limit(5)->pluck('name')->all() : [];
                    $problems[] = sprintf('%s: %d soft-deleted rows ', $table, $count).implode(', ', array_filter($names, is_string(...)));
                }
            }
        }

        $nodes = $this->tables->legacyTable('nodes');
        if ($schema->hasTable($nodes)) {
            $documents = $db->table($nodes.' as n')->where('n.kind', 'document');
            $versions = $this->tables->legacyTable('node_document_versions');
            if ($schema->hasTable($versions)) {
                $documents->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from($versions.' as r')->whereColumn('r.node_id', 'n.id'));
            }

            $count = $documents->count();
            if ($count > 0) {
                $problems[] = sprintf('%s: %d documents without revisions ', $nodes, $count).implode(', ', array_filter($documents->limit(5)->pluck('n.name')->all(), is_string(...)));
            }
        }

        foreach ($schema->getTables() as $table) {
            if (str_ends_with($table['name'], 'storages') && $table['name'] !== $this->tables->legacyTable('storages')) {
                $problems[] = 'own-tables: '.$table['name'];
            }
        }

        return $problems;
    }
}
