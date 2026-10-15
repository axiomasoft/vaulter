<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Changes;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Changes\ChangeJournal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Changes\ChangeOperation;

/**
 * Row work for v_drive_changes. Only {@see ChangeJournal} inserts and compacts
 * entries, only the prune and the drive purge delete them; it opens no transactions.
 */
#[Internal]
final readonly class ChangeEntryRepository
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
    ) {}

    public function exists(string $changeId): bool
    {
        return $this->query()->where('change_id', $changeId)->exists();
    }

    /**
     * Delete the earlier upserts of a node that a new entry supersedes.
     * A remove entry is never deleted here.
     *
     * @param list<string> $keptTypes event types whose upserts survive (an empty list keeps none)
     */
    public function compact(int|string $driveId, string $nodeId, bool $onlyWithoutSubtree, array $keptTypes): int
    {
        return $this->query()
            ->where('drive_id', $driveId)
            ->where('node_id', $nodeId)
            ->where('operation', ChangeOperation::Upsert->value)
            ->when($onlyWithoutSubtree, static fn (Builder $query) => $query->where('subtree', false))
            ->when($keptTypes !== [], static fn (Builder $query) => $query->whereNotIn('event_type', $keptTypes))
            ->delete();
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): void
    {
        $this->query()->insert($row);
    }

    /**
     * The entries after $after up to $through, in seq order: a plain read, in the same
     * snapshot as the nodes they are projected with.
     *
     * @return list<object{seq: int|string, change_id: string, event_type: string, node_id: int|string, operation: string, reason: ?string, subtree: bool|int|string, node_sequence: int|string|null}>
     */
    public function page(int|string $driveId, int $after, int $through, int $limit): array
    {
        $query = $this->query()
            ->where('drive_id', $driveId)
            ->where('seq', '>', $after)
            ->where('seq', '<=', $through)
            ->orderBy('seq')
            ->limit($limit);

        /** @var list<object{seq: int|string, change_id: string, event_type: string, node_id: int|string, operation: string, reason: ?string, subtree: bool|int|string, node_sequence: int|string|null}> */
        return $query->get(['seq', 'change_id', 'event_type', 'node_id', 'operation', 'reason', 'subtree', 'node_sequence'])->all();
    }

    /** The highest seq that occurred at or before $threshold, or null when none did. */
    public function boundary(int|string $driveId, CarbonInterface $threshold): ?int
    {
        $seq = $this->query()->where('drive_id', $driveId)->where('occurred_at', '<=', $threshold)->max('seq');

        return $seq === null ? null : ChangeHeadRepository::integer($seq);
    }

    /**
     * Delete up to $limit of the oldest entries at or below $boundary: the highest seq
     * deleted and how many went, or null when nothing was left.
     *
     * @return array{seq: int, count: int}|null
     */
    public function deleteOldest(int|string $driveId, int $boundary, int $limit): ?array
    {
        $last = $this->query()->where('drive_id', $driveId)->where('seq', '<=', $boundary)
            ->orderBy('seq')->offset($limit - 1)->limit(1)->value('seq')
            ?? $this->query()->where('drive_id', $driveId)->where('seq', '<=', $boundary)->max('seq');
        if ($last === null) {
            return null;
        }
        $seq = ChangeHeadRepository::integer($last);
        $count = $this->query()->where('drive_id', $driveId)->where('seq', '<=', $seq)->delete();

        return ['seq' => $seq, 'count' => $count];
    }

    public function deleteForDrive(int|string $driveId): void
    {
        $this->query()->where('drive_id', $driveId)->delete();
    }

    private function query(): Builder
    {
        return $this->database->connection()->table($this->tables->table('drive_changes'));
    }
}
