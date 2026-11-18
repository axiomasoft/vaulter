<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\ChangesOptions;
use Vaulter\Drive\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;

/**
 * Delete change-journal entries older than the retention window, drive by
 * drive, in batches of {@see self::BATCH}. Schedule it daily, like vaulter:webhooks:prune.
 *
 * Each batch holds the drive's journal head FOR UPDATE and raises pruned_through in the
 * same transaction as its DELETE, so a reader either sees the entries or learns that
 * its cursor expired (410), never a silent gap. Mutations of the drive wait for at most
 * one batch.
 */
#[Internal]
final class PruneChangesCommand extends Command
{
    use OpensWriteScope;

    public const int BATCH = 1000;

    protected $signature = 'vaulter:changes:prune
        {--days= : Override the retention window in days (config vaulter-drive.changes.retention_days)}
        {--drive=* : Only these drive ids}';

    protected $description = 'Delete drive change-journal entries past the retention window.';

    public function handle(ChangeHeadRepository $heads, ChangeEntryRepository $entries, VaulterDatabase $database, ChangesOptions $config): int
    {
        $override = $this->option('days');
        if ($override !== null && (preg_match('/^\d+$/', $override) !== 1 || (int) $override < 1)) {
            $this->components->error('--days must be a positive integer.');

            return self::INVALID;
        }
        $days = $override === null ? $config->retentionDays : (int) $override;
        $threshold = Carbon::now()->subDays($days);
        $only = array_values(array_filter(array_map(static fn (mixed $id): string => $id ?? '', $this->option('drive')), static fn (string $id): bool => $id !== ''));

        $deleted = 0;
        $drives = 0;
        foreach ($heads->driveIds() as $driveId) {
            if ($only !== [] && !in_array((string) $driveId, $only, true)) {
                continue;
            }
            $boundary = $entries->boundary($driveId, $threshold);
            if ($boundary === null) {
                continue;
            }
            $drives++;
            do {
                $batch = $database->transaction(static function () use ($heads, $entries, $driveId, $boundary): ?array {
                    if ($heads->find($driveId, 'update') === null) {
                        return null;
                    }
                    $batch = $entries->deleteOldest($driveId, $boundary, self::BATCH);
                    if ($batch !== null) {
                        $heads->markPruned($driveId, $batch['seq']);
                    }

                    return $batch;
                });
                $deleted += $batch['count'] ?? 0;
            } while ($batch !== null && $batch['count'] === self::BATCH);
        }

        $this->components->info(sprintf(
            'Pruned %d change entr%s from %d drive%s older than %s (retention %d day%s).',
            $deleted,
            $deleted === 1 ? 'y' : 'ies',
            $drives,
            $drives === 1 ? '' : 's',
            $threshold->toDateTimeString(),
            $days,
            $days === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
