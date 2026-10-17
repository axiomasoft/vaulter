<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\RecoverScanWorkAction;
use Vaulter\Drive\Actions\Content\SupersedeScanWorkAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Tenancy\TenancyBoundary;

#[Internal]
final readonly class ScanWorkReconciler
{
    public function __construct(private VaulterDatabase $database, private VaulterConfig $config,
        private DriveConfig $driveConfig, private ScanObligationRepository $work,
        private RecoverScanWorkAction $recover, private SupersedeScanWorkAction $supersede,
        private ScanPolicyResolver $policies, private Dispatcher $bus) {}

    public function afterCommit(ScanObligation $work): void
    {
        $this->database->afterCommit(fn () => $this->publish($work));
    }

    public function publish(ScanObligation $work): bool
    {
        try {
            $job = new ScanFileRevision($work->id, $work->generation, $work->drive_id, $work->tenant_id);
            $job->onQueue($this->driveConfig->scanQueue());
            $this->bus->dispatch($job);

            return true;
        } catch (\Throwable) {
            Log::warning('Vaulter scanner publication failed; durable work remains due.');

            return false;
        }
    }

    /** @param list<int|string>|Builder<Drive>|null $driveIds
     * @return array<string,int>
     */
    public function reconcile(int $limit = 100, int $maxSeconds = 30, bool $dryRun = false, array|Builder|null $driveIds = null): array
    {
        $started = microtime(true);
        $now = $this->work->now();
        $cooldown = $this->driveConfig->scanWork('publish_cooldown_seconds');
        $counts = ['scanned' => 0, 'published' => 0, 'failed' => 0, 'stalled_configuration' => 0, 'remaining_due' => 0];
        $class = $this->config->model('scan_obligation');
        $query = app(TenancyBoundary::class)->constrain($class::query())->whereIn('state', ['pending', 'retry_wait', 'leased'])
            ->where(function ($query) use ($now, $cooldown): void {
                $query->where(function ($query) use ($now, $cooldown): void {
                    $query->whereIn('state', ['pending', 'retry_wait'])->where('available_at', '<=', $now)
                        ->where(fn ($q) => $q->whereNull('last_published_at')->orWhere('last_published_at', '<=', $now->modify('-'.$cooldown.' seconds')));
                })->orWhere(fn ($q) => $q->where('state', 'leased')->where('lease_expires_at', '<=', $now));
            });
        if ($driveIds !== null) {
            $query->whereIn('drive_id', $driveIds);
        }
        foreach ($query->orderBy('available_at')->orderBy('id')->limit($limit)->get() as $row) {
            if (microtime(true) - $started >= $maxSeconds) {
                break;
            }
            $counts['scanned']++;
            try {
                $driveClass = $this->config->model('drive');
                $drive = app(TenancyBoundary::class)->constrain($driveClass::query())->whereKey($row->drive_id)->first();
                if ($drive === null || $drive->tenant_id !== $row->tenant_id) {
                    $counts['failed']++;

                    continue;
                }
                $specs = $this->policies->forDrive($drive);
                if (!array_any($specs, fn ($spec): bool => $spec->specKey() === $row->spec_key)) {
                    if (!$dryRun) {
                        $this->supersede->execute($row);
                    }
                    $counts['failed']++;

                    continue;
                }
            } catch (InvalidConfigurationException) {
                $counts['stalled_configuration']++;

                continue;
            }
            if ($dryRun) {
                $counts['published'] += $row->state === 'leased' ? 0 : 1;

                continue;
            }
            if ($row->state === 'leased') {
                $this->recover->execute($row, $this->work->now());
                $row = $this->work->find($row->id);
            }
            if ($row === null || $row->state === 'settled' || $row->state === 'leased') {
                continue;
            }
            $claimed = $this->database->transaction(function () use ($class, $row, $cooldown): ?ScanObligation {
                $fresh = $class::query()->whereKey($row->id)->lockForUpdate()->first();
                $current = $this->work->now();
                if ($fresh === null || !in_array($fresh->state, ['pending', 'retry_wait'], true)
                    || $fresh->available_at > $current
                    || ($fresh->last_published_at !== null && $fresh->last_published_at->addSeconds($cooldown) > $current)) {
                    return null;
                }
                $fresh->forceFill(['last_published_at' => $current])->save();

                return $fresh;
            });
            if ($claimed !== null) {
                if ($this->publish($claimed)) {
                    $counts['published']++;
                } else {
                    $class::query()->whereKey($claimed->id)->where('generation', $claimed->generation)
                        ->whereIn('state', ['pending', 'retry_wait'])->update(['available_at' => $this->work->now()->modify('+'.$cooldown.' seconds')]);
                }
            }
        }
        $counts['remaining_due'] = (clone $query)->count();

        return $counts;
    }
}
