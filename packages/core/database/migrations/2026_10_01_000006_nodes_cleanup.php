<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Activity\Activity;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Before 0.3 an Action could write a node with no parent outside the drive root: a
 * second root, missing from the root listing and outside sibling-name uniqueness.
 * Each such node is moved under its drive's root (renamed "<base> (legacy N)<.ext>"
 * when a live root child already owns the name), every move is written to the
 * activity log, and the one-root-per-drive index is added. Idempotent: a re-run
 * finds nothing to move and the index in place. The moves are not undone by down().
 */
return new class extends Migration
{
    /** The width of the nodes name column (varchar(255)). */
    private const int NAME_MAX_BYTES = 255;

    public function up(): void
    {
        $connection = app(VaulterDatabase::class)->connection();
        $nodes = Config::table('nodes');

        if (!$connection->getSchemaBuilder()->hasTable($nodes)) {
            return;
        }

        $connection->transaction(function () use ($connection, $nodes): void {
            $this->refuseUnknownRoots($connection, $nodes);
            $this->repairSecondRoots($connection, $nodes);
        });

        if (!$connection->getSchemaBuilder()->hasIndex($nodes, $this->indexName($nodes))) {
            app(SchemaBuilder::class)->addSingleRootIndex(table: $nodes);
        }
    }

    public function down(): void
    {
        $connection = app(VaulterDatabase::class)->connection();
        $nodes = Config::table('nodes');
        $schema = $connection->getSchemaBuilder();

        if (!$schema->hasTable($nodes)) {
            return;
        }

        if ($schema->hasIndex($nodes, $this->indexName($nodes))) {
            $schema->table($nodes, fn (Blueprint $table) => $table->dropIndex($this->indexName($nodes)));
        }

        if ($schema->hasColumn($nodes, 'root_storage')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->dropColumn('root_storage'));
        }
    }

    /**
     * A drive without a recorded root cannot be repaired by guessing which of its
     * parentless nodes is the root; stop before changing anything.
     */
    private function refuseUnknownRoots(Connection $connection, string $nodes): void
    {
        $ambiguous = $connection->table($nodes.' as n')
            ->join(Config::table('drives').' as s', 's.id', '=', 'n.drive_id')
            ->whereNull('n.parent_id')
            ->whereNull('s.root_node_id')
            ->groupBy('n.drive_id')
            ->havingRaw('count(*) > 1')
            ->pluck('n.drive_id')
            ->all();

        if ($ambiguous !== []) {
            throw new RuntimeException(
                '[Vaulter] Storages ['.implode(', ', array_map(Identity::stringify(...), $ambiguous)).'] have several parentless nodes '
                .'and no root_node_id. Fix: set root_node_id to the real root folder of each, then run the migration again.',
            );
        }
    }

    private function repairSecondRoots(Connection $connection, string $nodes): void
    {
        $orphans = $connection->table($nodes.' as n')
            ->join(Config::table('drives').' as s', 's.id', '=', 'n.drive_id')
            ->whereNull('n.parent_id')
            ->whereNotNull('s.root_node_id')
            ->whereColumn('n.id', '<>', 's.root_node_id')
            ->orderBy('n.drive_id')
            ->orderBy('n.id')
            ->get(['n.id', 'n.tenant_id', 'n.drive_id', 'n.name', 'n.trashed_at', 'n.deleted_at', 's.root_node_id']);

        $nodeModel = Config::nodeModel();
        $targetType = (new $nodeModel)->getMorphClass();

        foreach ($orphans as $orphan) {
            $id = $this->key($orphan->id);
            $rootId = $this->key($orphan->root_node_id);
            $original = is_string($orphan->name) ? $orphan->name : '';
            $live = $orphan->trashed_at === null && $orphan->deleted_at === null;
            $name = $live ? $this->freeName($connection, $nodes, $id, $rootId, $original) : $original;

            $connection->table($nodes)->where('id', $id)->update([
                'parent_id' => $rootId,
                'name' => $name,
            ]);

            $activityModel = Config::activityModel();

            /** @var Activity $activity */
            $activity = new $activityModel;
            $activity->forceFill([
                'tenant_id' => Identity::key($orphan->tenant_id),
                'drive_id' => $this->key($orphan->drive_id),
                'actor_type' => 'vaulter:system',
                'actor_id' => null,
                'verb' => 'node.moved',
                'target_type' => $targetType,
                'target_id' => $id,
                'context' => [
                    'from_parent_id' => null,
                    'to_parent_id' => Identity::stringify($rootId),
                    'reason' => 'n04_repair',
                    'renamed_from' => $name === $original ? null : $original,
                ],
                'created_at' => now(),
            ])->save();
        }
    }

    /**
     * The name, or the first "<base> (legacy N)<.ext>" no live child of the root owns
     * (case-insensitive, like sibling-unique). The base is shortened, on a UTF-8
     * character boundary, so the candidate still fits the name column.
     */
    private function freeName(Connection $connection, string $nodes, int|string $id, int|string $rootId, string $name): string
    {
        $dot = strrpos($name, '.');
        [$base, $extension] = $dot === false || $dot === 0 ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
        $candidate = $name;

        for ($n = 1; $this->nameTaken($connection, $nodes, $id, $rootId, $candidate); $n++) {
            $marker = sprintf(' (legacy %d)', $n);
            // An extension too long to keep beside the marker is shortened with the base.
            [$head, $tail] = strlen($marker.$extension) < self::NAME_MAX_BYTES ? [$base, $extension] : [$name, ''];
            $candidate = mb_strcut($head, 0, self::NAME_MAX_BYTES - strlen($marker.$tail), 'UTF-8').$marker.$tail;
        }

        return $candidate;
    }

    private function nameTaken(Connection $connection, string $nodes, int|string $id, int|string $rootId, string $name): bool
    {
        return $connection->table($nodes)
            ->where('parent_id', $rootId)
            ->where('id', '<>', $id)
            ->whereNull('trashed_at')
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /**
     * Ids selected from a NOT NULL key column are always int or string.
     */
    private function key(mixed $value): int|string
    {
        return Identity::key($value) ?? throw new UnexpectedValueException('[Vaulter] Unexpected node key '.get_debug_type($value).'.');
    }

    private function indexName(string $nodes): string
    {
        return $nodes.'_root_uniq';
    }
};
