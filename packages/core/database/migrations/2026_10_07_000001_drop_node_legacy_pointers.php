<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Identity;

/**
 * Removes the 0.2 node columns 0.3 no longer has: the polymorphic head pointer
 * (current_version_id) and file_id — heads live on v_files / v_documents now — and
 * the soft-delete tombstone (deleted_at): purge is a real DELETE. Sibling-name
 * uniqueness is rebuilt without the tombstone. Each step checks the current state
 * first, so a re-run after a partial MySQL failure finishes the job.
 */
return new class extends Migration
{
    private const array LEGACY_COLUMNS = ['current_version_id', 'file_id', 'deleted_at'];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $nodes = app(DatabaseConfig::class)->table('nodes');

        if (!$schema->hasTable($nodes)) {
            return;
        }

        $columns = $schema->getColumnListing($nodes);
        if (in_array('deleted_at', $columns, true) && $db->table($nodes)->whereNotNull('deleted_at')->exists()) {
            $ids = $db->table($nodes)->whereNotNull('deleted_at')->orderBy('id')->limit(50)->pluck('id')->all();

            throw new UpgradePreflightException(['soft-deleted nodes ['.implode(', ', array_map(Identity::stringify(...), $ids)).'] must be purged first']);
        }

        if ($this->siblingIndexUsesTombstone($db, $nodes)) {
            $this->dropSiblingIndex($db, $nodes);
        }

        foreach (['current_version_id', 'file_id'] as $column) {
            if ($schema->hasIndex($nodes, $nodes.'_'.$column.'_index')) {
                $schema->table($nodes, fn (Blueprint $table) => $table->dropIndex($nodes.'_'.$column.'_index'));
            }
        }

        $present = array_values(array_intersect(self::LEGACY_COLUMNS, $columns));
        if ($present !== []) {
            $schema->table($nodes, fn (Blueprint $table) => $table->dropColumn($present));
        }

        if (!$schema->hasIndex($nodes, $nodes.'_sibling_uniq')) {
            $this->createSiblingIndex($db, $nodes, withTombstone: false);
        }
    }

    public function down(): void
    {
        if ($this->freshInstall()) {
            return;
        }

        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $nodes = app(DatabaseConfig::class)->table('nodes');

        if (!$schema->hasTable($nodes) || $schema->hasColumn($nodes, 'deleted_at')) {
            return;
        }

        $ids = app(IdStrategyResolver::class)->resolve();
        $schema->table($nodes, function (Blueprint $table) use ($ids): void {
            $ids->reference(table: $table, column: 'current_version_id', nullable: true);
            $ids->reference(table: $table, column: 'file_id', nullable: true);
            $table->softDeletes();
        });

        // 0.2 read both heads from the node; put them back from the aggregates.
        $files = app(DatabaseConfig::class)->table('files');
        $documents = app(DatabaseConfig::class)->table('documents');
        $head = $schema->hasTable($documents)
            ? "COALESCE((SELECT f.current_revision_id FROM {$files} f WHERE f.node_id = {$nodes}.id), (SELECT d.current_revision_id FROM {$documents} d WHERE d.node_id = {$nodes}.id))"
            : "(SELECT f.current_revision_id FROM {$files} f WHERE f.node_id = {$nodes}.id)";
        $db->statement("UPDATE {$nodes} SET file_id = (SELECT f.id FROM {$files} f WHERE f.node_id = {$nodes}.id), current_version_id = {$head}");

        $this->dropSiblingIndex($db, $nodes);
        $this->createSiblingIndex($db, $nodes, withTombstone: true);
    }

    /** Whether the live sibling-unique index still excludes soft-deleted rows. */
    private function siblingIndexUsesTombstone(Connection $db, string $nodes): bool
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasIndex($nodes, $nodes.'_sibling_uniq')) {
            return false;
        }

        $definition = match ($db->getDriverName()) {
            'pgsql' => $db->table('pg_indexes')->whereRaw('schemaname = current_schema()')->where('indexname', $nodes.'_sibling_uniq')->value('indexdef'),
            'sqlite' => $db->table('sqlite_master')->where('type', 'index')->where('name', $nodes.'_sibling_uniq')->value('sql'),
            'mysql' => $db->table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $nodes)->where('INDEX_NAME', $nodes.'_sibling_uniq')->whereNotNull('EXPRESSION')->value('EXPRESSION'),
            default => $schema->hasColumn($nodes, 'sibling_active') && $schema->hasColumn($nodes, 'deleted_at') ? 'deleted_at' : '',
        };

        return is_string($definition) && str_contains($definition, 'deleted_at');
    }

    private function dropSiblingIndex(Connection $db, string $nodes): void
    {
        $schema = $db->getSchemaBuilder();
        if ($schema->hasIndex($nodes, $nodes.'_sibling_uniq')) {
            $schema->table($nodes, fn (Blueprint $table) => $table->dropIndex($nodes.'_sibling_uniq'));
        }

        if ($schema->hasColumn($nodes, 'sibling_active')) {
            $schema->table($nodes, fn (Blueprint $table) => $table->dropColumn('sibling_active'));
        }
    }

    /** Literal copies of the sibling-unique index per driver (0.3, and 0.2 for down()). */
    private function createSiblingIndex(Connection $db, string $nodes, bool $withTombstone): void
    {
        $name = $nodes.'_sibling_uniq';
        $live = $withTombstone ? 'deleted_at IS NULL AND trashed_at IS NULL' : 'trashed_at IS NULL';
        $liveQuoted = $withTombstone ? '`deleted_at` IS NULL AND `trashed_at` IS NULL' : '`trashed_at` IS NULL';

        match ($db->getDriverName()) {
            'pgsql', 'sqlite' => $db->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, lower(name)) WHERE %s', $name, $nodes, $live)),
            'mysql' => $db->statement(sprintf('CREATE UNIQUE INDEX `%s` ON `%s` (`parent_id`, ((CASE WHEN %s THEN LOWER(`name`) ELSE NULL END)))', $name, $nodes, $liveQuoted)),
            'mariadb' => $this->createMariaDbSiblingIndex($db, $nodes, $name, $liveQuoted),
            default => $db->getSchemaBuilder()->table($nodes, fn (Blueprint $table) => $table->unique(['parent_id', 'name'], $name)),
        };
    }

    private function createMariaDbSiblingIndex(Connection $db, string $nodes, string $name, string $live): void
    {
        $db->statement(sprintf("ALTER TABLE `%s` ADD COLUMN `sibling_active` VARCHAR(512) GENERATED ALWAYS AS (CASE WHEN %s THEN CONCAT(COALESCE(`parent_id`, ''), ':', LOWER(`name`)) ELSE NULL END) STORED", $nodes, $live));
        $db->statement(sprintf('ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`sibling_active`)', $nodes, $name));
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000001_drop_node_legacy_pointers')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
