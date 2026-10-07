<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Identity;

/**
 * File revisions get restored_from_revision_id (a restore copies a revision forward
 * and remembers its source), and v_files gets the composite head FK
 * (current_revision_id, id) → v_file_revisions(id, file_id): a head can only be a
 * revision of the same file and cannot be pruned. A head that already points at
 * another file's revision is reported, not repaired. SQLite cannot add constraints
 * to an existing table, so there the Actions keep the head invariant alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $files = app(DatabaseConfig::class)->table('files');
        $revisions = app(DatabaseConfig::class)->table('file_revisions');

        if (!$schema->hasTable($files) || !$schema->hasTable($revisions)) {
            return;
        }

        $foreign = $db->table($files.' as f')->whereNotNull('f.current_revision_id')
            ->whereNotExists(fn (Builder $sub): Builder => $sub->from($revisions.' as r')->whereColumn('r.id', 'f.current_revision_id')->whereColumn('r.file_id', 'f.id'))
            ->orderBy('f.id')->pluck('f.id')->all();
        if ($foreign !== []) {
            throw new UpgradePreflightException(['files whose head is not one of their revisions ['.implode(', ', array_map(Identity::stringify(...), $foreign)).']']);
        }

        if (!$schema->hasColumn($revisions, 'restored_from_revision_id')) {
            $ids = app(IdStrategyResolver::class)->resolve();
            $sqlite = $db->getDriverName() === 'sqlite';
            $schema->table($revisions, function (Blueprint $table) use ($ids, $revisions, $sqlite): void {
                $ids->reference(table: $table, column: 'restored_from_revision_id', nullable: true);
                if (!$sqlite) {
                    $table->foreign('restored_from_revision_id', $revisions.'_restored_fk')->references('id')->on($revisions)->nullOnDelete();
                }
            });
        }

        if (!$schema->hasIndex($revisions, $revisions.'_id_file_uniq')) {
            $schema->table($revisions, fn (Blueprint $table) => $table->unique(['id', 'file_id'], $revisions.'_id_file_uniq'));
        }

        if ($db->getDriverName() !== 'sqlite' && !$this->hasForeignKey($db, $files, $files.'_head_fk')) {
            $grammar = $db->getQueryGrammar();
            $db->statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s, %s) REFERENCES %s (%s, %s) ON DELETE RESTRICT',
                $grammar->wrapTable($files),
                $grammar->wrap($files.'_head_fk'),
                $grammar->wrap('current_revision_id'),
                $grammar->wrap('id'),
                $grammar->wrapTable($revisions),
                $grammar->wrap('id'),
                $grammar->wrap('file_id'),
            ));
        }
    }

    public function down(): void
    {
        if ($this->freshInstall()) {
            return;
        }

        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $files = app(DatabaseConfig::class)->table('files');
        $revisions = app(DatabaseConfig::class)->table('file_revisions');

        if (!$schema->hasTable($files) || !$schema->hasTable($revisions)) {
            return;
        }

        if ($this->hasForeignKey($db, $files, $files.'_head_fk')) {
            $schema->table($files, fn (Blueprint $table) => $table->dropForeign($files.'_head_fk'));
        }

        if ($schema->hasIndex($revisions, $revisions.'_id_file_uniq')) {
            $schema->table($revisions, fn (Blueprint $table) => $table->dropUnique($revisions.'_id_file_uniq'));
        }

        if ($schema->hasColumn($revisions, 'restored_from_revision_id')) {
            $schema->table($revisions, function (Blueprint $table) use ($db, $revisions): void {
                if ($this->hasForeignKey($db, $revisions, $revisions.'_restored_fk')) {
                    $table->dropForeign($revisions.'_restored_fk');
                }
                $table->dropIndex($revisions.'_restored_from_revision_id_index');
                $table->dropColumn('restored_from_revision_id');
            });
        }
    }

    private function hasForeignKey(Connection $db, string $table, string $name): bool
    {
        return in_array($name, array_column($db->getSchemaBuilder()->getForeignKeys($table), 'name'), true);
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000002_revision_provenance_and_heads')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
