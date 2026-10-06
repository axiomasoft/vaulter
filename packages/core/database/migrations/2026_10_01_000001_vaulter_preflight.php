<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;

return new class extends Migration
{
    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable(app(DatabaseConfig::class)->legacyTable('storages')) || $schema->hasTable(app(DatabaseConfig::class)->table('drives'))) {
            return;
        }

        $problems = [];
        foreach (['nodes', 'storages', 'files'] as $entity) {
            $table = app(DatabaseConfig::class)->legacyTable($entity);
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'deleted_at')) {
                $count = $db->table($table)->whereNotNull('deleted_at')->count();
                if ($count > 0) {
                    $names = $schema->hasColumn($table, 'name') ? $db->table($table)->whereNotNull('deleted_at')->limit(5)->pluck('name')->all() : [];
                    $problems[] = sprintf('%s: %d soft-deleted rows ', $table, $count).implode(', ', array_filter($names, is_string(...)));
                }
            }
        }

        $nodes = app(DatabaseConfig::class)->legacyTable('nodes');
        if ($schema->hasTable($nodes)) {
            $documents = $db->table($nodes.' as n')->where('n.kind', 'document');
            $versions = app(DatabaseConfig::class)->legacyTable('node_document_versions');
            if ($schema->hasTable($versions)) {
                $documents->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from($versions.' as r')->whereColumn('r.node_id', 'n.id'));
            }

            $count = $documents->count();
            if ($count > 0) {
                $problems[] = sprintf('%s: %d documents without revisions ', $nodes, $count).implode(', ', array_filter($documents->limit(5)->pluck('n.name')->all(), is_string(...)));
            }
        }

        foreach ($schema->getTables() as $table) {
            if (str_ends_with($table['name'], 'storages') && $table['name'] !== app(DatabaseConfig::class)->legacyTable('storages')) {
                $problems[] = 'own-tables: '.$table['name'];
            }
        }

        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    public function down(): void {}
};
