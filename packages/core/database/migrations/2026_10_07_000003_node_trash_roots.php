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

/**
 * Explicit trash roots (PLAN2.D37): v_nodes.trash_root_id names the node a trashed
 * node was trashed with; a root points at itself, every live node holds null. The
 * backfill is deterministic and idempotent:
 *  1. a trashed node that remembers its parent (trashed_from_parent_id), has no parent
 *     or hangs under a live parent is a root — it was trashed on its own;
 *  2. every other trashed node takes the root of its trashed parent, level by level
 *     from the roots down, in batches of 500 ordered by id.
 * A trash_root_id column of another shape stops the migration; a re-run after a
 * failure only touches rows that still have no root.
 */
return new class extends Migration
{
    private const int BATCH = 500;

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $nodes = $this->table('nodes');

        if (!$schema->hasTable($nodes)) {
            return;
        }

        if ($schema->hasColumn($nodes, 'trash_root_id')) {
            $this->refuseUnknownShape($db, $nodes);
        } else {
            $ids = app(IdStrategyResolver::class)->resolve();
            $schema->table($nodes, static fn (Blueprint $table) => $ids->reference(table: $table, column: 'trash_root_id', nullable: true));
        }

        if (!$schema->hasIndex($nodes, ['drive_id', 'trash_root_id'])) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->index(['drive_id', 'trash_root_id']));
        }

        $this->backfillRoots($db, $nodes);
        $this->backfillMembers($db, $nodes);
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $nodes = $this->table('nodes');

        // A fresh install created the column in the base shell, which drops it.
        if ($this->freshInstall() || !$schema->hasTable($nodes) || !$schema->hasColumn($nodes, 'trash_root_id')) {
            return;
        }

        $schema->table($nodes, static function (Blueprint $table) use ($schema, $nodes): void {
            if ($schema->hasIndex($nodes, ['drive_id', 'trash_root_id'])) {
                $table->dropIndex(['drive_id', 'trash_root_id']);
            }
            if ($schema->hasIndex($nodes, ['trash_root_id'])) {
                $table->dropIndex(['trash_root_id']);
            }
            $table->dropColumn('trash_root_id');
        });
    }

    private function refuseUnknownShape(Connection $db, string $nodes): void
    {
        foreach ($db->getSchemaBuilder()->getColumns($nodes) as $column) {
            if ($column['name'] === 'trash_root_id' && !$column['nullable']) {
                throw new UpgradePreflightException([$nodes.'.trash_root_id exists as not null; expected a nullable reference']);
            }
        }
    }

    private function backfillRoots(Connection $db, string $nodes): void
    {
        do {
            $ids = $db->table($nodes.' as c')->leftJoin($nodes.' as p', 'p.id', '=', 'c.parent_id')
                ->whereNotNull('c.trashed_at')->whereNull('c.trash_root_id')
                ->where(static fn (Builder $query): Builder => $query->whereNotNull('c.trashed_from_parent_id')->orWhereNull('p.id')->orWhereNull('p.trashed_at'))
                ->orderBy('c.id')->limit(self::BATCH)->pluck('c.id')->all();
            if ($ids !== []) {
                $db->table($nodes)->whereIn('id', $ids)->update(['trash_root_id' => $db->raw('id')]);
            }
        } while (count($ids) === self::BATCH);
    }

    private function backfillMembers(Connection $db, string $nodes): void
    {
        do {
            $rows = $db->table($nodes.' as c')->join($nodes.' as p', 'p.id', '=', 'c.parent_id')
                ->whereNotNull('c.trashed_at')->whereNull('c.trash_root_id')->whereNotNull('p.trash_root_id')
                ->orderBy('c.id')->limit(self::BATCH)->get(['c.id as id', 'p.trash_root_id as root']);
            foreach ($rows as $row) {
                $db->table($nodes)->where('id', $row->id)->whereNull('trash_root_id')->update(['trash_root_id' => $row->root]);
            }
        } while ($rows->isNotEmpty());
    }

    private function table(string $entity): string
    {
        return app(DatabaseConfig::class)->table($entity);
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000008_create_v_nodes_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000003_node_trash_roots')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
