<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\SchemaBuilder;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->freshInstall()) {
            return;
        }

        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        foreach (['storages', 'nodes', 'blobs', 'files', 'file_versions', 'file_version_blobs', 'node_grants', 'node_shares', 'node_comments', 'node_document_versions', 'upload_sessions', 'upload_parts', 'activity', 'outbox', 'webhook_endpoints', 'webhook_deliveries'] as $entity) {
            $legacy = app(DatabaseConfig::class)->legacyTable($entity);
            $standard = app(DatabaseConfig::class)->table($entity);
            if ($legacy !== $standard) {
                if ($schema->hasTable($legacy) && $schema->hasTable($standard)) {
                    throw new RuntimeException(sprintf('Conflicting legacy tables: %s and %s', $legacy, $standard));
                }

                $this->renameTable($legacy, $standard);
                $this->renameKeys($entity, [$legacy => $standard]);
            }
        }

        $this->renameTable(app(DatabaseConfig::class)->table('storages'), app(DatabaseConfig::class)->table('drives'));
        foreach (['drives', 'nodes', 'outbox'] as $entity) {
            $this->renameColumn($entity, 'panel', 'profile');
            $name = app(DatabaseConfig::class)->table($entity);
            if ($schema->hasTable($name)) {
                $columns = array_column($schema->getColumns($name), null, 'name');
                if (($columns['profile']['type'] ?? '') !== 'character varying(64)' && ($columns['profile']['type'] ?? '') !== 'varchar(64)') {
                    $schema->table($name, fn (Blueprint $table) => $table->string('profile', 64)->default('default')->change());
                }
            }
        }

        foreach (['nodes', 'blobs', 'upload_sessions', 'activity'] as $entity) {
            $this->renameColumn($entity, 'storage_id', 'drive_id');
        }

        $drives = app(DatabaseConfig::class)->table('drives');
        if ($schema->hasIndex($drives, app(DatabaseConfig::class)->table('storages').'_owner_panel_uniq')) {
            $schema->table($drives, fn (Blueprint $table) => $table->dropIndex(app(DatabaseConfig::class)->table('storages').'_owner_panel_uniq'));
        }

        $this->dropColumns('drives', ['owner_active', 'storage_type', 'slug', 'default_node_permissions', 'deleted_at']);
        if (!$schema->hasIndex($drives, $drives.'_owner_profile_uniq')) {
            $schema->table($drives, fn (Blueprint $table) => $table->unique(['owner_type', 'owner_id', 'profile'], $drives.'_owner_profile_uniq'));
        }

        foreach (['drives', 'nodes', 'blobs', 'upload_sessions', 'activity', 'outbox'] as $entity) {
            $this->renameKeys($entity, ['storages' => 'drives', 'storage_id' => 'drive_id', '_storage_idx' => '_drive_idx', '_panel_' => '_profile_']);
        }

        // Revision tables wait for #4 when their 0.2 equivalents exist.
        foreach (['drives', 'nodes', 'blobs', 'files', 'node_grants', 'node_shares', 'upload_sessions', 'upload_parts', 'activity', 'outbox', 'webhook_endpoints', 'webhook_deliveries', 'node_document_versions', 'node_comments'] as $entity) {
            if (!$schema->hasTable(app(DatabaseConfig::class)->table($entity))) {
                (new SchemaBuilder)->createTable($entity);
            }
        }
    }

    public function down(): void
    {
        // Fresh-install rollback must leave canonical tables for the base migrations
        // to drop; only an earlier 0.2 batch needs historical names restored.
        if ($this->freshInstall()) {
            return;
        }

        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $drives = app(DatabaseConfig::class)->table('drives');
        if (!$schema->hasTable($drives)) {
            return;
        }

        foreach (['storage_type', 'slug', 'default_node_permissions', 'deleted_at'] as $column) {
            if (!$schema->hasColumn($drives, $column)) {
                $schema->table($drives, static function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'storage_type' => $table->string($column, 32)->default('private')->index(),
                        'slug' => $table->string($column, 160)->nullable(),
                        'default_node_permissions' => $table->unsignedInteger($column)->default(31),
                        'deleted_at' => $table->timestamp($column)->nullable(),
                    };
                });
            }
        }

        if ($schema->hasIndex($drives, $drives.'_owner_profile_uniq')) {
            $schema->table($drives, fn (Blueprint $table) => $table->dropUnique($drives.'_owner_profile_uniq'));
        }

        foreach (['drives', 'nodes', 'outbox'] as $entity) {
            $this->renameColumn($entity, 'profile', 'panel');
            if ($schema->hasTable(app(DatabaseConfig::class)->table($entity))) {
                $schema->table(app(DatabaseConfig::class)->table($entity), fn (Blueprint $table) => $table->string('panel')->default('default')->change());
            }
        }

        foreach (['nodes', 'blobs', 'upload_sessions', 'activity'] as $entity) {
            $this->renameColumn($entity, 'drive_id', 'storage_id');
        }

        foreach (['drives', 'nodes', 'blobs', 'upload_sessions', 'activity', 'outbox'] as $entity) {
            $this->renameKeys($entity, ['drives' => 'storages', 'drive_id' => 'storage_id', '_drive_idx' => '_storage_idx', '_profile_' => '_panel_']);
        }

        $this->renameTable($drives, app(DatabaseConfig::class)->table('storages'));
        $db = app(VaulterDatabase::class)->connection();
        $name = app(DatabaseConfig::class)->table('storages');
        if (!$schema->hasIndex($name, $name.'_owner_panel_uniq')) {
            if ($db->getDriverName() === 'pgsql' || $db->getDriverName() === 'sqlite') {
                $g = $db->getQueryGrammar();
                $db->statement('CREATE UNIQUE INDEX '.$g->wrap($name.'_owner_panel_uniq').' ON '.$g->wrapTable($name).' (owner_type, owner_id, panel) WHERE deleted_at IS NULL');
            } else {
                $schema->table($name, fn (Blueprint $table) => $table->unique(['owner_type', 'owner_id', 'panel'], $name.'_owner_panel_uniq'));
            }
        }

        // Restore configured 0.2 physical names after restoring their canonical
        // historical names. Missing feature tables are deliberately retained.
        foreach (['storages', 'nodes', 'blobs', 'files', 'file_versions', 'file_version_blobs', 'node_grants', 'node_shares', 'node_comments', 'node_document_versions', 'upload_sessions', 'upload_parts', 'activity', 'outbox', 'webhook_endpoints', 'webhook_deliveries'] as $entity) {
            $standard = app(DatabaseConfig::class)->table($entity);
            $legacy = app(DatabaseConfig::class)->legacyTable($entity);
            if ($standard !== $legacy) {
                $this->renameKeys($entity, [$standard => $legacy]);
                $this->renameTable($standard, $legacy);
            }
        }
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_01_000002_rename_storages_to_drives')->value('batch')
            ?? $db->table('migrations')->where('migration', '2026_10_01_000001_vaulter_preflight')->value('batch');

        return $base !== null && $base === $upgrade;
    }

    private function renameColumn(string $entity, string $from, string $to): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table($entity);
        if ($schema->hasTable($name) && $schema->hasColumn($name, $from) && !$schema->hasColumn($name, $to)) {
            $schema->table($name, fn (Blueprint $table) => $table->renameColumn($from, $to));
        }
    }

    private function renameTable(string $from, string $to): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable($from) && !$schema->hasTable($to)) {
            $schema->rename($from, $to);
        }

        if ($db->getDriverName() === 'pgsql' && $schema->hasTable($to)) {
            $grammar = $db->getQueryGrammar();
            if ($db->scalar('select to_regclass(?)', [$from.'_id_seq']) !== null
                && $db->scalar('select to_regclass(?)', [$to.'_id_seq']) === null) {
                $db->statement('ALTER SEQUENCE '.$grammar->wrapTable($from.'_id_seq').' RENAME TO '.$grammar->wrap($to.'_id_seq'));
            }
        }
    }

    /** @param array<string, string> $replacements */
    private function renameKeys(string $entity, array $replacements): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table($entity);
        if (!$schema->hasTable($name)) {
            return;
        }

        $grammar = $db->getQueryGrammar();
        foreach ($schema->getForeignKeys($name) as $key) {
            if (!is_string($key['name'])) {
                continue;
            }

            $next = strtr($key['name'], $replacements);
            if ($next !== $key['name'] && $db->getDriverName() === 'pgsql') {
                $db->statement('ALTER TABLE '.$grammar->wrapTable($name).' RENAME CONSTRAINT '.$grammar->wrap($key['name']).' TO '.$grammar->wrap($next));
            }
        }

        foreach ($schema->getIndexes($name) as $index) {
            $next = strtr($index['name'], $replacements);
            if ($next !== $index['name'] && !$schema->hasIndex($name, $next)) {
                if ($db->getDriverName() === 'pgsql' && $index['primary']) {
                    $db->statement('ALTER TABLE '.$grammar->wrapTable($name).' RENAME CONSTRAINT '.$grammar->wrap($index['name']).' TO '.$grammar->wrap($next));
                } else {
                    $schema->table($name, fn (Blueprint $table) => $table->renameIndex($index['name'], $next));
                }
            }
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(string $entity, array $columns): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table($entity);
        if (!$schema->hasTable($name)) {
            return;
        }

        foreach ($columns as $column) {
            if ($schema->hasColumn($name, $column)) {
                foreach ($schema->getIndexes($name) as $index) {
                    if (in_array($column, $index['columns'], true)) {
                        $schema->table($name, fn (Blueprint $table) => $index['unique'] ? $table->dropUnique($index['name']) : $table->dropIndex($index['name']));
                    }
                }

                $schema->table($name, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
