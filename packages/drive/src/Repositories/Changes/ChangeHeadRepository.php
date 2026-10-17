<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Changes;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Changes\ChangeJournal;
use Vaulter\Support\Identity;

/**
 * Row work for v_drive_change_heads, the per-drive counter of the change journal. It
 * opens no transactions: {@see ChangeJournal} raises the counter inside the mutation's
 * transaction, the prune and reset commands inside their own.
 */
#[Internal]
final readonly class ChangeHeadRepository
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
    ) {}

    /** Create the head of a drive unless it exists; true when this call created it. */
    public function create(int|string $driveId, ?string $tenantId): bool
    {
        return $this->database->insertOnce(table: $this->table(), row: [
            'drive_id' => $driveId,
            'tenant_id' => $tenantId,
            'last_seq' => 0,
            'pruned_through' => 0,
            'epoch' => 1,
            'updated_at' => now(),
        ], uniqueColumn: 'drive_id');
    }

    /**
     * Raise last_seq by one. The UPDATE locks the head row until the transaction ends,
     * which is what orders the journal's entries; false when the drive has no head yet.
     *
     * @phpstan-impure
     */
    public function advance(int|string $driveId): bool
    {
        return $this->database->connection()->table($this->table())->where('drive_id', $driveId)->update([
            'last_seq' => $this->database->connection()->raw('last_seq + 1'),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * The head of a drive, or null when it has none. $lock is 'share' (FOR SHARE: a
     * prune waits for the reader's transaction) or 'update' (FOR UPDATE: the prune and
     * reset hold it while they change the journal).
     *
     * @param  'none'|'share'|'update'                                    $lock
     * @return array{last_seq: int, pruned_through: int, epoch: int}|null
     */
    public function find(int|string $driveId, string $lock = 'none'): ?array
    {
        $query = $this->database->connection()->table($this->table())->where('drive_id', $driveId);
        match ($lock) {
            'share' => $query->sharedLock(),
            'update' => $query->lockForUpdate(),
            'none' => null,
        };
        $row = $query->first(['last_seq', 'pruned_through', 'epoch']);

        return $row === null ? null : [
            'last_seq' => self::integer($row->last_seq),
            'pruned_through' => self::integer($row->pruned_through),
            'epoch' => self::integer($row->epoch),
        ];
    }

    /** Record that every entry up to $seq is gone; never lowers the mark. */
    public function markPruned(int|string $driveId, int $seq): void
    {
        $this->database->connection()->table($this->table())->where('drive_id', $driveId)->where('pruned_through', '<', $seq)
            ->update(['pruned_through' => $seq, 'updated_at' => now()]);
    }

    /** Start a new epoch; every cursor of the drive expires. */
    public function bumpEpoch(int|string $driveId): bool
    {
        return $this->database->connection()->table($this->table())->where('drive_id', $driveId)->update([
            'epoch' => $this->database->connection()->raw('epoch + 1'),
            'updated_at' => now(),
        ]) > 0;
    }

    /** @return list<int|string> the drive ids with a head, in id order */
    public function driveIds(): array
    {
        return array_values(array_filter(
            $this->database->connection()->table($this->table())->orderBy('drive_id')->pluck('drive_id')
                ->map(static fn (mixed $id): int|string|null => Identity::key($id))->all(),
            static fn (int|string|null $id): bool => $id !== null,
        ));
    }

    public function delete(int|string $driveId): void
    {
        $this->database->connection()->table($this->table())->where('drive_id', $driveId)->delete();
    }

    /** Counters come back as strings from some drivers. */
    public static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function table(): string
    {
        return $this->tables->table('drive_change_heads');
    }
}
