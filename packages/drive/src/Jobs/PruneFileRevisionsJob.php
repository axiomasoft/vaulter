<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\RevisionRetentionPolicy;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * Apply the drive profile's RevisionRetentionPolicy to every file of one drive,
 * trashed files included. Protection order: the head, then pinned
 * revisions, then revisions superseded less than minNoncurrentAgeDays ago; of the
 * rest, everything past the newest maxNoncurrentRevisions unpinned non-current
 * revisions and everything superseded more than maxNoncurrentAgeDays ago goes.
 *
 * Files are scanned in batches of 500; every chosen revision is deleted through
 * DeleteFileRevisionAction as the system actor 'revision-retention', which re-checks
 * head and pin under the file and revision locks (P4-I19). A revision that became
 * the head or was pinned after the choice is skipped, not an error. Re-running
 * converges: a revision is either still a candidate or gone.
 */
#[Internal]
final class PruneFileRevisionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string ACTOR = DeleteFileRevisionAction::RETENTION_REASON;

    private const int BATCH = 500;

    public int $tries = 1;

    public function __construct(public int|string $driveId, public bool $dryRun = false) {}

    public function uniqueId(): string
    {
        return 'vaulter-revisions-prune:'.Identity::stringify($this->driveId);
    }

    public function handle(DeleteFileRevisionAction $delete, ProfileRegistry $profiles, VaulterDatabase $database, DatabaseConfig $tables, VaulterConfig $config): void
    {
        $database->write(fn (): array => $this->run($delete, $profiles, $database, $tables, $config));
    }

    /** @return array{candidates: int, deleted: int, skipped: int, freed_bytes: int} */
    public function run(DeleteFileRevisionAction $delete, ProfileRegistry $profiles, VaulterDatabase $database, DatabaseConfig $tables, VaulterConfig $config): array
    {
        return app(VaulterManager::class)->withoutTenancy(function () use ($delete, $profiles, $database, $tables, $config): array {
            $report = ['candidates' => 0, 'deleted' => 0, 'skipped' => 0, 'freed_bytes' => 0];
            $drive = $config->model('drive')::query()->whereKey($this->driveId)->first();
            if (!$drive instanceof Drive) {
                return $report;
            }

            $policy = $profiles->get($drive->profile)->getDrivePolicy()->revisionRetention;
            if (!$policy->prunes()) {
                return $report;
            }

            $context = DriveContext::system(drive: $drive, reason: self::ACTOR);
            $now = CarbonImmutable::now();
            $after = null;
            do {
                $rows = $database->connection()->table($tables->table('files').' as f')
                    ->join($tables->table('nodes').' as n', 'n.id', '=', 'f.node_id')
                    ->where('n.drive_id', $drive->id)
                    ->when($after !== null, static fn ($query) => $query->where('f.id', '>', $after))
                    ->orderBy('f.id')->limit(self::BATCH)
                    ->get(['f.id', 'f.node_id', 'f.current_revision_id']);
                $files = [];
                foreach ($rows as $row) {
                    $files[] = ['id' => $this->key($row->id), 'node_id' => $this->key($row->node_id), 'head' => Identity::key($row->current_revision_id)];
                }

                if ($files === []) {
                    break;
                }

                $after = $files[array_key_last($files)]['id'];

                $candidates = $this->candidates($database, $tables, $files, $policy, $now);
                $report['candidates'] += count($candidates);
                if ($this->dryRun) {
                    $report['freed_bytes'] += array_sum(array_column($candidates, 'size_bytes'));

                    continue;
                }

                $nodes = $config->model('node')::query()->whereKey(array_values(array_unique(array_column($candidates, 'node_id'))))->get()
                    ->keyBy(static fn (Node $node): string => Identity::stringify($node->id));
                foreach ($candidates as $candidate) {
                    $node = $nodes->get(Identity::stringify($candidate['node_id']));
                    if (!$node instanceof Node) {
                        $report['skipped']++;

                        continue;
                    }

                    try {
                        $delete->execute($context, $node, $candidate['id']);
                        $report['deleted']++;
                        $report['freed_bytes'] += $candidate['size_bytes'];
                    } catch (CannotDeleteHeadRevisionException|RevisionPinnedException|NodeNotFoundException) {
                        $report['skipped']++;
                    }
                }
            } while (count($files) === self::BATCH);

            return $report;
        });
    }

    /**
     * The revisions of these files the policy deletes, chosen from a read without
     * locks; the delete re-checks what can change under its locks.
     *
     * @param  list<array{id: int|string, node_id: int|string, head: int|string|null}> $files
     * @return list<array{id: int|string, node_id: int|string, size_bytes: int}>
     */
    private function candidates(VaulterDatabase $database, DatabaseConfig $tables, array $files, RevisionRetentionPolicy $policy, CarbonImmutable $now): array
    {
        $heads = [];
        $nodes = [];
        foreach ($files as $file) {
            $heads[Identity::stringify($file['id'])] = $file['head'] === null ? null : Identity::stringify($file['head']);
            $nodes[Identity::stringify($file['id'])] = $file['node_id'];
        }

        $youngest = $now->subDays($policy->minNoncurrentAgeDays);
        $oldest = $policy->maxNoncurrentAgeDays === null ? null : $now->subDays($policy->maxNoncurrentAgeDays);

        $rows = $database->connection()->table($tables->table('file_revisions'))
            ->whereIn('file_id', array_column($files, 'id'))
            ->where('keep_forever', false)
            ->orderBy('file_id')->orderByDesc('number')
            ->get(['id', 'file_id', 'size_bytes', 'superseded_at']);

        $chosen = [];
        $rank = [];
        foreach ($rows as $row) {
            $fileId = Identity::stringify($row->file_id);
            if (Identity::stringify($row->id) === $heads[$fileId]) {
                continue;
            }

            // The newest unpinned non-current revisions come first; pinned ones do not count.
            $position = $rank[$fileId] = ($rank[$fileId] ?? -1) + 1;
            if (!is_string($row->superseded_at) && !$row->superseded_at instanceof \DateTimeInterface) {
                continue;
            }

            $superseded = CarbonImmutable::parse($row->superseded_at);
            if ($superseded->greaterThan($youngest)) {
                continue;
            }

            $overCount = $policy->maxNoncurrentRevisions !== null && $position >= $policy->maxNoncurrentRevisions;
            $overAge = $oldest !== null && $superseded->lessThanOrEqualTo($oldest);
            if ($overCount || $overAge) {
                $chosen[] = ['id' => $this->key($row->id), 'node_id' => $nodes[$fileId], 'size_bytes' => is_numeric($row->size_bytes) ? (int) $row->size_bytes : 0];
            }
        }

        return $chosen;
    }

    private function key(mixed $value): int|string
    {
        return Identity::key($value) ?? throw new LogicException('A persisted row has no identifier.');
    }
}
