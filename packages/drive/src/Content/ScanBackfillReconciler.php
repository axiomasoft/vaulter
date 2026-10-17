<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\RequestScanWorkAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

#[Internal]
final readonly class ScanBackfillReconciler
{
    public function __construct(private DriveConfig $config, private VaulterDatabase $database,
        private ScanPolicyResolver $policies, private ScanSnapshot $snapshots,
        private RequestScanWorkAction $request, private ScanWorkReconciler $publisher) {}

    /** @param list<int|string>|Builder<Drive>|null $driveIds
     * @return array<string,int>
     */
    public function expand(int $limit = 100, int $maxSeconds = 30, bool $dryRun = false, array|Builder|null $driveIds = null): array
    {
        $started = microtime(true);
        $counts = ['backfill_materialized' => 0, 'backfill_remaining' => 0, 'stalled_configuration' => 0];
        $class = $this->config->model('scan_backfill_run');
        $runs = app(TenancyBoundary::class)->constrain($class::query())->where('state', 'pending')->orderBy('id');
        if ($driveIds !== null) {
            $runs->whereIn('drive_id', $driveIds);
        }
        $processed = 0;
        foreach ($runs->limit($limit)->get() as $run) {
            $driveClass = $this->config->model('drive');
            $drive = app(TenancyBoundary::class)->constrain($driveClass::query())->whereKey($run->drive_id)->first();
            if ($drive === null || $drive->tenant_id !== $run->tenant_id) {
                continue;
            }
            try {
                $spec = array_find($this->policies->forDrive($drive), fn ($spec): bool => $spec->specKey() === $run->spec_key);
            } catch (InvalidConfigurationException) {
                $counts['stalled_configuration']++;

                continue;
            }
            if ($spec === null) {
                if (!$dryRun) {
                    $class::query()->whereKey($run->id)->where('state', 'pending')->where('target_sequence', $run->target_sequence)->update(['state' => 'completed']);
                }

                continue;
            }
            $revisionClass = $this->config->model('file_revision');
            $query = $revisionClass::query()->whereHas('file.node', fn ($nodes) => $nodes->where('drive_id', $drive->id))
                ->where('scan_admission_sequence', '<=', $run->target_sequence)
                ->where(function ($query) use ($run): void {
                    $query->where('scan_admission_sequence', '>', $run->last_admission_sequence);
                    if ($run->last_revision_id !== null) {
                        $query->orWhere(function ($query) use ($run): void {
                            $query->where('scan_admission_sequence', $run->last_admission_sequence)->where('id', '>', $run->last_revision_id);
                        });
                    } else {
                        $query->orWhere('scan_admission_sequence', $run->last_admission_sequence);
                    }
                })->orderBy('scan_admission_sequence')->orderBy('id');
            $page = $query->limit($limit - $processed)->get();
            foreach ($page as $revision) {
                if ($processed >= $limit || microtime(true) - $started >= $maxSeconds) {
                    break 2;
                }
                $processed++;
                if ($dryRun) {
                    $counts['backfill_materialized']++;

                    continue;
                }
                try {
                    $advanced = $this->database->transaction(function () use ($revision, $run, $spec, $drive, $class, $revisionClass): bool {
                        $this->database->lockAggregateShared('drives', $drive->id);
                        $selected = $revisionClass::query()->whereKey($revision->id)->first();
                        $obligation = null;
                        if ($selected !== null) {
                            $snapshot = $this->snapshots->load($selected, true);
                            $context = DriveContext::system($snapshot['drive'], 'scan policy backfill');
                            $this->policies->forDrive($snapshot['drive']);
                            $obligation = $this->request->ensure($snapshot['revision'], $spec, $run->request_key, $context);
                        }
                        $cursor = $class::query()->whereKey($run->id)->lockForUpdate()->first();
                        if ($cursor === null || $cursor->last_admission_sequence !== $run->last_admission_sequence
                            || (string) $cursor->last_revision_id !== (string) $run->last_revision_id) {
                            // Throwing rolls back materialization made by a competing cursor loser.
                            throw new \RuntimeException('Scan backfill cursor changed.');
                        }
                        $cursor->forceFill(['last_admission_sequence' => $revision->scan_admission_sequence,
                            'last_revision_id' => $revision->id])->save();
                        if ($obligation !== null && $obligation->state !== 'settled') {
                            $this->publisher->afterCommit($obligation);
                        }

                        return $obligation !== null;
                    });
                } catch (\RuntimeException $exception) {
                    if ($exception->getMessage() !== 'Scan backfill cursor changed.') {
                        throw $exception;
                    }
                    break;
                }
                $run->refresh();
                $counts['backfill_materialized'] += $advanced ? 1 : 0;
            }
            if (!$dryRun && $page->isEmpty()) {
                $class::query()->whereKey($run->id)->where('target_sequence', $run->target_sequence)
                    ->where('last_admission_sequence', $run->last_admission_sequence)
                    ->where('last_revision_id', $run->last_revision_id)->update(['state' => 'completed']);
            }
        }
        $counts['backfill_remaining'] = (clone $runs)->count();

        return $counts;
    }
}
