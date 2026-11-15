<?php

declare(strict_types=1);

namespace Vaulter\Drive\Quota;

use Illuminate\Database\Query\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Support\Identity;

/**
 * The quota formula: a drive uses the sum of size_bytes of every
 * retained revision of its files, trashed files included, without any discount for
 * deduplicated bytes. v_drives.used_bytes is the eager cache of this sum, kept by
 * conditional UPDATEs on every path that adds or removes a revision; the upgrade
 * migration and recounts compute it here.
 *
 * Reads the tables directly (no Eloquent scopes), so it works inside migrations and
 * for system maintenance alike.
 *
 * The public quota view is DriveHandle::quota().
 */
#[Internal]
final readonly class QuotaCalculator
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
    ) {}

    public function usedBytes(int|string $driveId): int
    {
        return $this->usedBytesByDrive([$driveId])[Identity::stringify($driveId)] ?? 0;
    }

    /** The part of usedBytes() held by files in the trash. */
    public function trashedBytes(int|string $driveId): int
    {
        return self::bytes($this->revisions([$driveId])->whereNotNull('n.trashed_at')->sum('r.size_bytes'));
    }

    /** SUM() comes back as an int, a numeric string (PostgreSQL bigint sums) or null. */
    private static function bytes(mixed $sum): int
    {
        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @param  list<int|string>   $driveIds
     * @return array<string, int> drive id (as a string) => used bytes; drives without revisions are absent
     */
    public function usedBytesByDrive(array $driveIds): array
    {
        if ($driveIds === []) {
            return [];
        }

        $used = [];
        foreach ($this->revisions($driveIds)->groupBy('n.drive_id')->selectRaw('n.drive_id as drive_id, sum(r.size_bytes) as used')->get() as $row) {
            $used[Identity::stringify($row->drive_id)] = self::bytes($row->used);
        }

        return $used;
    }

    /** @param list<int|string> $driveIds */
    private function revisions(array $driveIds): Builder
    {
        return $this->database->connection()->table($this->tables->table('file_revisions').' as r')
            ->join($this->tables->table('files').' as f', 'f.id', '=', 'r.file_id')
            ->join($this->tables->table('nodes').' as n', 'n.id', '=', 'f.node_id')
            ->whereIn('n.drive_id', $driveIds);
    }
}
