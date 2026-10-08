<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Quota\QuotaCalculator;
use Vaulter\Support\Identity;

/**
 * Revision lifecycle and the quota over every retained revision (PLAN2.D48):
 *  - v_file_revisions.superseded_at, the moment a revision stopped being the head;
 *    the retention policy counts the non-current age from it. Backfill: committed_at
 *    of the next revision of the same file (now() when there is none).
 *  - v_drives.used_bytes is recounted for every drive as the sum of size_bytes of
 *    all retained revisions, trash included, without a dedup discount
 *    (QuotaCalculator). A drive that ends up over its quota keeps working for reads
 *    and deletes; only growth is refused.
 *
 * A re-run after a failure finishes the job: the backfill touches only rows still
 * without superseded_at, the recount is a pure function of the revisions. A
 * superseded_at column of another shape stops the migration. Batches of 500 by id.
 */
return new class extends Migration
{
    private const int BATCH = 500;

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $revisions = $this->table('file_revisions');

        if (!$schema->hasTable($revisions) || !$schema->hasTable($this->table('drives'))) {
            return;
        }

        if ($schema->hasColumn($revisions, 'superseded_at')) {
            $this->refuseUnknownShape($db, $revisions);
        } else {
            $schema->table($revisions, static fn (Blueprint $table) => $table->timestamp('superseded_at')->nullable());
        }

        $this->backfillSupersededAt($db, $revisions);
        $this->recount($db, fn (array $driveIds): array => app(QuotaCalculator::class)->usedBytesByDrive($driveIds));
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $revisions = $this->table('file_revisions');

        // A fresh install created the column in the base shells, which drop it.
        if ($this->freshInstall() || !$schema->hasTable($revisions) || !$schema->hasColumn($revisions, 'superseded_at')) {
            return;
        }

        $schema->table($revisions, static fn (Blueprint $table) => $table->dropColumn('superseded_at'));
        // Back to the previous formula: the heads of all files, trash included.
        $this->recount($db, fn (array $driveIds): array => $this->headBytes($db, $driveIds));
    }

    private function refuseUnknownShape(Connection $db, string $revisions): void
    {
        foreach ($db->getSchemaBuilder()->getColumns($revisions) as $column) {
            if ($column['name'] !== 'superseded_at') {
                continue;
            }
            $type = strtolower((string) $column['type_name']);
            if (!$column['nullable'] || (!str_contains($type, 'timestamp') && !str_contains($type, 'datetime'))) {
                throw new UpgradePreflightException([$revisions.'.superseded_at exists as '.$type.($column['nullable'] ? ' null' : ' not null').'; expected a nullable timestamp']);
            }
        }
    }

    private function backfillSupersededAt(Connection $db, string $revisions): void
    {
        $files = $this->table('files');
        $after = null;
        do {
            $batch = $db->table($files)->when($after !== null, static fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'current_revision_id']);
            if ($batch->isEmpty()) {
                break;
            }
            $after = $batch->last()->id;
            $heads = [];
            foreach ($batch as $file) {
                $heads[Identity::stringify($file->id)] = $file->current_revision_id === null ? null : Identity::stringify($file->current_revision_id);
            }

            $rows = $db->table($revisions)->whereIn('file_id', $batch->pluck('id')->all())
                ->orderBy('file_id')->orderBy('number')->get(['id', 'file_id', 'committed_at', 'created_at', 'superseded_at']);
            $byFile = $rows->groupBy(static fn (object $row): string => Identity::stringify($row->file_id));
            foreach ($byFile as $fileId => $chain) {
                $chain = $chain->values();
                foreach ($chain as $index => $row) {
                    if ($row->superseded_at !== null || Identity::stringify($row->id) === $heads[$fileId]) {
                        continue;
                    }
                    $next = $chain->get($index + 1);
                    $db->table($revisions)->where('id', $row->id)->whereNull('superseded_at')
                        ->update(['superseded_at' => $next->committed_at ?? $next->created_at ?? now()]);
                }
            }
        } while ($batch->count() === self::BATCH);
    }

    /** @param Closure(list<int|string>): array<string, int> $usage */
    private function recount(Connection $db, Closure $usage): void
    {
        $drives = $this->table('drives');
        $db->table($drives)->select('id')->chunkById(self::BATCH, function ($batch) use ($db, $drives, $usage): void {
            $ids = [];
            foreach ($batch as $row) {
                $ids[] = Identity::key($row->id) ?? throw new LogicException('A drive row has no identifier.');
            }
            $used = $usage($ids);
            foreach ($ids as $id) {
                $db->table($drives)->where('id', $id)->update(['used_bytes' => $used[Identity::stringify($id)] ?? 0]);
            }
        });
    }

    /**
     * @param  list<int|string>   $driveIds
     * @return array<string, int>
     */
    private function headBytes(Connection $db, array $driveIds): array
    {
        $used = [];
        $rows = $db->table($this->table('files').' as f')->join($this->table('nodes').' as n', 'n.id', '=', 'f.node_id')
            ->whereIn('n.drive_id', $driveIds)->groupBy('n.drive_id')
            ->selectRaw('n.drive_id as drive_id, sum(f.size_bytes) as used')->get();
        foreach ($rows as $row) {
            $used[Identity::stringify($row->drive_id)] = is_numeric($row->used) ? (int) $row->used : 0;
        }

        return $used;
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

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000007_quota_all_revisions')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
