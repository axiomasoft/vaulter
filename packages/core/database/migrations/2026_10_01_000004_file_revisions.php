<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        foreach (['file_versions' => 'file_revisions', 'file_version_blobs' => 'file_revision_blobs'] as $from => $to) {
            $this->renameTable(Config::table($from), Config::table($to));
            if (!$schema->hasTable(Config::table($to))) {
                (new SchemaBuilder)->createTable($to);
            }
        }

        foreach (['file_revisions' => ['version_no' => 'number', 'content_sha256' => 'sha256'], 'file_revision_blobs' => ['file_version_id' => 'file_revision_id'], 'files' => ['current_version_id' => 'current_revision_id', 'version_count' => 'revision_count'], 'upload_sessions' => ['node_id' => 'target_node_id']] as $entity => $columns) {
            foreach ($columns as $from => $to) {
                $this->renameColumn($entity, $from, $to);
            }
        }

        $name = Config::table('file_revisions');
        foreach (['declared_mime_type', 'scan_status'] as $column) {
            if (!$schema->hasColumn($name, $column)) {
                $schema->table($name, static function (Blueprint $table) use ($column): void {
                    $column === 'declared_mime_type' ? $table->string($column, 191)->nullable() : $table->string($column, 16)->default('not_required');
                });
            }
        }

        foreach (['result_node_id', 'result_revision_id'] as $column) {
            $name = Config::table('upload_sessions');
            if (!$schema->hasColumn($name, $column)) {
                $schema->table($name, fn (Blueprint $table) => app(IdStrategyResolver::class)->resolve()->reference($table, $column, nullable: true));
            }
        }

        $this->dropColumns('files', ['deleted_at']);
        foreach (['file_revisions', 'file_revision_blobs', 'files', 'upload_sessions'] as $entity) {
            $this->renameKeys($entity, ['file_version_blobs' => 'file_revision_blobs', 'file_versions' => 'file_revisions', 'file_version_id' => 'file_revision_id', 'content_sha256' => 'sha256', 'version_no' => 'number', 'current_version_id' => 'current_revision_id', Config::table('upload_sessions').'_node_id_index' => Config::table('upload_sessions').'_target_node_id_index']);
        }
    }

    public function down(): void
    {
        // Fresh-install rollback must leave canonical tables for the base migrations
        // to drop; only an earlier 0.2 batch needs historical names restored.
        if ($this->freshInstall()) {
            return;
        }

        $this->dropColumns('upload_sessions', ['result_node_id', 'result_revision_id']);
        $this->dropColumns('file_revisions', ['declared_mime_type', 'scan_status']);
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        if ($schema->hasTable(Config::table('files')) && !$schema->hasColumn(Config::table('files'), 'deleted_at')) {
            $schema->table(Config::table('files'), fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
        }

        foreach (['file_revisions' => ['number' => 'version_no', 'sha256' => 'content_sha256'], 'file_revision_blobs' => ['file_revision_id' => 'file_version_id'], 'files' => ['current_revision_id' => 'current_version_id', 'revision_count' => 'version_count'], 'upload_sessions' => ['target_node_id' => 'node_id']] as $entity => $columns) {
            foreach ($columns as $from => $to) {
                $this->renameColumn($entity, $from, $to);
            }
        }

        foreach (['file_revisions', 'file_revision_blobs', 'files', 'upload_sessions'] as $entity) {
            $this->renameKeys($entity, ['file_revision_blobs' => 'file_version_blobs', 'file_revisions' => 'file_versions', 'file_revision_id' => 'file_version_id', 'sha256_index' => 'content_sha256_index', 'number' => 'version_no', 'current_revision_id' => 'current_version_id', Config::table('upload_sessions').'_target_node_id_index' => Config::table('upload_sessions').'_node_id_index']);
        }

        $this->renameTable(Config::table('file_revision_blobs'), Config::table('file_version_blobs'));
        $this->renameTable(Config::table('file_revisions'), Config::table('file_versions'));
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_01_000004_file_revisions')->value('batch')
            ?? $db->table('migrations')->where('migration', '2026_10_01_000001_vaulter_preflight')->value('batch');

        return $base !== null && $base === $upgrade;
    }

    private function renameColumn(string $entity, string $from, string $to): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $name = Config::table($entity);
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
        $name = Config::table($entity);
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
        $name = Config::table($entity);
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
