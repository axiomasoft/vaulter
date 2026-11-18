<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Jobs\PurgeDriveJob;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

/**
 * Start or resume the purge of the drives that are being deleted. The
 * purge itself is idempotent, so running this again after a crash finishes the job.
 */
#[Internal]
final class PurgeDrivesCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:drives:purge {--drive=* : Only these drive ids} {--dry-run : Print what would be purged} {--sync : Purge in this process instead of queueing}';

    protected $description = 'Purge drives with status deleting: nodes, revisions and blob references, then the drive row.';

    public function handle(DriveOptions $config, PurgeDriveStepAction $step): int
    {
        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');
        $only = array_values(array_filter(array_map(Identity::stringify(...), $this->option('drive')), static fn (string $id): bool => $id !== ''));
        $failed = false;
        $done = 0;

        $drives = Tenancy::without(fn (): array => $config->model('drive')::query()
            ->where('status', DriveStatus::Deleting->value)
            ->when($only !== [], static fn (Builder $query): Builder => $query->whereKey($only))
            ->orderBy('id')->pluck('id')->all());
        foreach ($drives as $id) {
            $key = Identity::key($id);
            if ($key === null) {
                continue;
            }
            if ($dryRun) {
                $nodes = Tenancy::without(fn (): int => $config->model('node')::query()->where('drive_id', $key)->count());
                $this->line(sprintf('Drive %s: %d node%s would be purged.', Identity::stringify($key), $nodes, $nodes === 1 ? '' : 's'));

                continue;
            }
            try {
                if ($sync) {
                    while (!$step->execute($key)) {
                        // One batch per turn; the purge converges.
                    }
                } else {
                    dispatch(new PurgeDriveJob($key));
                }
                $done++;
            } catch (Throwable $exception) {
                $failed = true;
                Log::error('Vaulter drive purge failed.', [
                    'drive_id' => Identity::stringify($key),
                    'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
                ]);
                $this->error('Purge failed for drive '.Identity::stringify($key).'.');
            }
        }
        if (!$dryRun) {
            $this->info(sprintf('%s %d drive%s.', $sync ? 'Purged' : 'Queued the purge of', $done, $done === 1 ? '' : 's'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
