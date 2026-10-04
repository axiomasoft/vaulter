<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $grants = Config::table('node_grants');
        $shares = Config::table('node_shares');
        if ($schema->hasColumn($grants, 'deleted_at')) {
            $db->table($grants)->whereNotNull('deleted_at')->delete();
            if ($schema->hasIndex($grants, $grants.'_grant_uniq')) {
                $schema->table($grants, fn (Blueprint $table) => $table->dropIndex($grants.'_grant_uniq'));
            }
        }

        if ($schema->hasColumn($shares, 'deleted_at')) {
            $db->table($shares)->whereNotNull('deleted_at')->update(['revoked_at' => $db->raw('COALESCE(revoked_at, deleted_at)')]);
        }

        $this->dropColumns('node_grants', ['grant_active', 'inherited', 'inherited_from_node_id', 'deleted_at']);
        $this->dropColumns('node_shares', ['deleted_at']);
        if (!$schema->hasIndex($grants, $grants.'_grant_uniq')) {
            $schema->table($grants, fn (Blueprint $table) => $table->unique(['node_id', 'subject_type', 'subject_id', 'scope', 'effect'], $grants.'_grant_uniq'));
        }
    }

    public function down(): void
    {
        // Fresh-install rollback must leave canonical tables for the base migrations
        // to drop; only an earlier 0.2 batch needs historical names restored.
        if ($this->freshInstall()) {
            return;
        }

        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        foreach (['node_grants', 'node_shares'] as $entity) {
            $name = Config::table($entity);
            if ($schema->hasTable($name) && !$schema->hasColumn($name, 'deleted_at')) {
                $schema->table($name, fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
            }
        }

        $name = Config::table('node_grants');
        if (!$schema->hasTable($name)) {
            return;
        }

        if (!$schema->hasColumn($name, 'inherited')) {
            $schema->table($name, fn (Blueprint $table) => $table->boolean('inherited')->default(false)->index());
        }

        if (!$schema->hasColumn($name, 'inherited_from_node_id')) {
            $schema->table($name, fn (Blueprint $table) => app(IdStrategyResolver::class)->resolve()->reference($table, 'inherited_from_node_id', nullable: true));
        }

        if ($schema->hasIndex($name, $name.'_grant_uniq')) {
            $schema->table($name, fn (Blueprint $table) => $table->dropUnique($name.'_grant_uniq'));
        }

        $g = $db->getQueryGrammar();
        if (in_array($db->getDriverName(), ['pgsql', 'sqlite'], true)) {
            $db->statement('CREATE UNIQUE INDEX '.$g->wrap($name.'_grant_uniq').' ON '.$g->wrapTable($name).' (node_id, subject_type, subject_id, scope, effect) WHERE deleted_at IS NULL');
        } else {
            if (!$schema->hasColumn($name, 'grant_active')) {
                $schema->table($name, fn (Blueprint $table) => $table->string('grant_active', 700)->storedAs("CASE WHEN deleted_at IS NULL THEN CONCAT(node_id, ':', subject_type, ':', subject_id, ':', scope, ':', effect) ELSE NULL END"));
            }

            $schema->table($name, fn (Blueprint $table) => $table->unique('grant_active', $name.'_grant_uniq'));
        }
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_01_000008_grants_shares_cleanup')->value('batch')
            ?? $db->table('migrations')->where('migration', '2026_10_01_000001_vaulter_preflight')->value('batch');

        return $base !== null && $base === $upgrade;
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
