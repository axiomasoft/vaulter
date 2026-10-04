<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;

/**
 * F-P4.1-01 / F-P4.1-02 (VLT-REMED P4.3, batch B16): the trashed_at scan index
 * `vaulter:purge-trash`'s predicate needs — without it every scheduled run is a
 * full v_nodes seq-scan (findings/P4.1-query-indexes.md C5) — plus removal of
 * three indexes whose column set is a strict prefix (v_nodes, v_blobs) or a
 * literal duplicate (v_activity) of another index already on the same table
 * (§0/C7): dead write cost, zero read benefit.
 *
 * A fresh install already gets the corrected shape straight from
 * SchemaBuilder (defineNodes/defineBlobs/defineActivity + the new
 * addTrashedAtIndex() hook no longer double-emit), so every step below first
 * inspects Schema::getIndexes() — this migration only does real work against a
 * database that ran the OLD shape before this fix shipped; re-running it (or
 * running it against a fresh install) is a no-op. Applied migrations are never
 * rewritten (package-migrations rule) — this is a NEW migration, not an edit of
 * one of the 2026_06_13_* shells.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addTrashedAtIndexIfMissing();
        $this->dropIndexByColumnsIfPresent(table: Config::table('nodes'), columns: ['storage_id', 'parent_id']);
        $this->dropIndexByColumnsIfPresent(table: Config::table('blobs'), columns: ['status']);
        $this->dropDuplicateActivityCreatedAtIndex();
    }

    public function down(): void
    {
        $this->restoreIndexByColumnsIfMissing(table: Config::table('nodes'), columns: ['storage_id', 'parent_id']);
        $this->restoreIndexByColumnsIfMissing(table: Config::table('blobs'), columns: ['status']);
        $this->restoreActivityCreatedAtIndexIfMissing();
        $this->dropTrashedAtIndexIfPresent();
    }

    private function addTrashedAtIndexIfMissing(): void
    {
        $table = Config::table('nodes');

        if (!Schema::hasTable($table) || $this->hasIndexOnColumns($table, ['trashed_at'])) {
            return;
        }

        $name = "{$table}_trashed_idx";
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement("CREATE INDEX {$name} ON {$table} (trashed_at) WHERE trashed_at IS NOT NULL");

            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->index('trashed_at', $name);
        });
    }

    private function dropTrashedAtIndexIfPresent(): void
    {
        $table = Config::table('nodes');
        $index = $this->findIndexByColumns($table, ['trashed_at']);

        if (!Schema::hasTable($table) || $index === null) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index['name']);
        });
    }

    /**
     * Drop a plain btree index whose column set is EXACTLY $columns — matched by
     * column set, not by name, because the index in question is Laravel's own
     * default-named one (no explicit name was ever given to it).
     *
     * @param list<string> $columns
     */
    private function dropIndexByColumnsIfPresent(string $table, array $columns): void
    {
        $index = $this->findIndexByColumns($table, $columns);

        if (!Schema::hasTable($table) || $index === null) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index['name']);
        });
    }

    /**
     * @param list<string> $columns
     */
    private function restoreIndexByColumnsIfMissing(string $table, array $columns): void
    {
        if (!Schema::hasTable($table) || $this->hasIndexOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        });
    }

    /**
     * v_activity carries created_at TWICE on a pre-fix database — an unnamed
     * inline index and the explicit `_created_idx`
     * (SchemaBuilder::addActivityCreatedAtIndex). Keep the named one (every
     * other feed index on this table already follows the `_xxx_idx` convention),
     * drop the other.
     */
    private function dropDuplicateActivityCreatedAtIndex(): void
    {
        $table = Config::table('activity');

        if (!Schema::hasTable($table)) {
            return;
        }

        $keep = "{$table}_created_idx";

        foreach ($this->indexesOnColumns($table, ['created_at']) as $index) {
            if ($index['name'] === $keep) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index['name']);
            });
        }
    }

    private function restoreActivityCreatedAtIndexIfMissing(): void
    {
        $table = Config::table('activity');

        if (!Schema::hasTable($table) || count($this->indexesOnColumns($table, ['created_at'])) >= 2) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->index('created_at');
        });
    }

    /**
     * @param list<string> $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        return $this->findIndexByColumns($table, $columns) !== null;
    }

    /**
     * @param  list<string>                                    $columns
     * @return array{name: string, columns: list<string>}|null
     */
    private function findIndexByColumns(string $table, array $columns): ?array
    {
        foreach ($this->indexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>                                     $columns
     * @return list<array{name: string, columns: list<string>}>
     */
    private function indexesOnColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $this->indexes($table),
            static fn (array $index): bool => $index['columns'] === $columns,
        ));
    }

    /**
     * @return list<array{name: string, columns: list<string>}>
     */
    private function indexes(string $table): array
    {
        /** @var list<array{name: string, columns: list<string>}> $indexes */
        $indexes = Schema::getIndexes($table);

        return $indexes;
    }
};
