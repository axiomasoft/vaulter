<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Drive\Actions\Drives\RecountDriveQuotaAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

/**
 * Recompute v_drives.used_bytes from the retained revisions, drive by drive, and
 * report the drift it finds. --dry-run reads and reports without writing.
 * It runs as maintenance outside any tenant, scoped to the drives it names.
 */
#[Internal]
final class RecountDrivesCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:drives:recount {--drive=* : Only these drive ids} {--dry-run : Report the drift without correcting it}';

    protected $description = 'Recount the used bytes of drives from their retained file revisions.';

    public function handle(RecountDriveQuotaAction $recount, DriveConfig $config): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = array_values(array_filter(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', (array) $this->option('drive')), static fn (string $id): bool => $id !== ''));
        $failed = false;
        $checked = 0;
        $drifted = 0;

        $ids = Tenancy::without(fn (): array => $config->model('drive')::query()
            ->where('status', '!=', DriveStatus::Deleting->value)
            ->when($only !== [], static fn ($query) => $query->whereKey($only))
            ->orderBy('id')->pluck('id')->all());
        foreach ($ids as $id) {
            $key = Identity::key($id);
            if ($key === null) {
                continue;
            }
            try {
                $report = Tenancy::without(fn (): array => $recount->execute($key, $dryRun));
            } catch (Throwable $exception) {
                $failed = true;
                Log::error('Vaulter drive recount failed.', [
                    'drive_id' => Identity::stringify($key),
                    'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
                ]);
                $this->error('Recount failed for drive '.Identity::stringify($key).'.');

                continue;
            }
            $checked++;
            if ($report['changed']) {
                $drifted++;
                Log::warning('Vaulter drive quota drift.', ['drive_id' => Identity::stringify($key), 'stored' => $report['before'],
                    'counted' => $report['after'], 'corrected' => !$dryRun]);
                $this->line(sprintf('Drive %s: stored %d, counted %d (%+d)%s.', Identity::stringify($key), $report['before'], $report['after'], $report['drift'], $dryRun ? ', not corrected' : ', corrected'));
            }
        }
        $this->info(sprintf('Checked %d drive%s, %d with drift%s.', $checked, $checked === 1 ? '' : 's', $drifted, $dryRun ? ' (dry run)' : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
