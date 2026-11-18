<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Jobs\PruneFileRevisionsJob;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

/**
 * Apply the revision retention policy of each drive's profile, drive by
 * drive, in this process. Schedule it daily, like vaulter:trash:purge; a drive that
 * fails is logged and the others still run.
 */
#[Internal]
final class PruneRevisionsCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:revisions:prune {--drive=* : Only these drive ids} {--dry-run : Report what would be deleted}';

    protected $description = 'Delete non-current file revisions past their profile retention policy.';

    public function handle(DeleteFileRevisionAction $delete, ProfileRegistry $profiles, VaulterDatabase $database, DatabaseOptions $tables, DriveOptions $config): int
    {
        $dryRun = $this->option('dry-run');
        $only = array_values(array_filter(array_map(Identity::stringify(...), $this->option('drive')), static fn (string $id): bool => $id !== ''));
        $totals = ['candidates' => 0, 'deleted' => 0, 'skipped' => 0, 'freed_bytes' => 0];
        $failed = false;

        $ids = Tenancy::without(fn (): array => $config->model('drive')::query()
            ->when($only !== [], static fn (Builder $query): Builder => $query->whereKey($only))
            ->orderBy('id')->pluck('id')->all());
        foreach ($ids as $id) {
            $key = Identity::key($id);
            if ($key === null) {
                continue;
            }
            try {
                $report = new PruneFileRevisionsJob($key, $dryRun)->run($delete, $profiles, $database, $tables, $config);
            } catch (Throwable $exception) {
                $failed = true;
                Log::error('Vaulter revision pruning failed.', [
                    'drive_id' => Identity::stringify($id),
                    'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
                ]);
                $this->error('Revision pruning failed for drive '.Identity::stringify($id).'.');

                continue;
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $report[$key];
            }
        }

        $this->info(sprintf(
            '%s %d revision%s (%d bytes); %d skipped.',
            $dryRun ? 'Would delete' : 'Deleted',
            $dryRun ? $totals['candidates'] : $totals['deleted'],
            ($dryRun ? $totals['candidates'] : $totals['deleted']) === 1 ? '' : 's',
            $totals['freed_bytes'],
            $totals['skipped'],
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
