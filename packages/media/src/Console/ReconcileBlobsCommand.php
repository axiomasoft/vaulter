<?php

declare(strict_types=1);

namespace Vaulter\Media\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Tenancy\Tenancy;

/**
 * Rebuild the ref_count cache from the pin counts (v_blob_references, D23) — the real
 * liveness truth — and report integrity hazards: dangling rows (a committed
 * blob whose canonical object is missing on disk). With --orphans it ALSO walks the
 * disk for the inverse hazard — orphan objects (bytes on disk with no blob row),
 * reporting them by default and DELETING them only under --prune. The default run
 * (no flags) is unchanged: row-side reconcile only.
 *
 * Two capability caveats (the reconciler enforces them, this command surfaces them):
 *  - The disk-walk needs a CAS driver that can enumerate the disk; a non-listable
 *    driver (e.g. S3 / non-FilesystemAdapter) is reported "listing unsupported" and
 *    skipped, not failed.
 *  - --prune deletes bytes under a write-less critical section, which only serialises
 *    against a concurrent commit on PostgreSQL/MySQL. On SQLite pruning is REFUSED
 *    (orphans are still detected + reported); run report-only there, or prune on the
 *    production DB.
 *
 * EXIT: report-only (or prune-refused) that FINDS orphans exits FAILURE so cron/CI
 * sees the hazard; a prune that cleaned them, and a clean run, exit SUCCESS.
 */
#[Internal]
final class ReconcileBlobsCommand extends Command
{
    use OpensWriteScope;

    public function __construct(
        private readonly MediaConfig $mediaConfig,
    ) {
        parent::__construct();
    }

    protected $signature = 'vaulter:blobs:reconcile
        {--orphans : Also walk the disk for orphan objects (bytes with no blob row)}
        {--disk= : The disk to scan for orphans (defaults to the configured CAS disk)}
        {--orphan-grace= : Override the orphan grace window in hours (floored at 1h)}
        {--prune : DELETE the orphans found (PostgreSQL/MySQL only; refused on sqlite). Default: report only}';

    protected $description = 'Rebuild blob ref_count from edge counts and report dangling rows; optionally disk-walk for orphan objects (H.3)';

    public function handle(BlobReconciler $reconciler): int
    {
        return Tenancy::without(fn (): int => $this->runMaintenance($reconciler));
    }

    private function runMaintenance(BlobReconciler $reconciler): int
    {
        $report = $reconciler->reconcile();

        $this->components->info(
            "Reconciled {$report['reconciled']} blobs, corrected {$report['changed']} ref_count caches.",
        );

        $exit = self::SUCCESS;

        if ($report['dangling'] !== []) {
            $this->components->warn(
                'Dangling rows (committed blob, missing object): '.implode(', ', $report['dangling']),
            );

            $exit = self::FAILURE;
        } else {
            $this->components->info('No dangling rows detected.');
        }

        if ($this->option('orphans')) {
            return $this->walkOrphans(reconciler: $reconciler, exit: $exit);
        }

        if ($this->option('prune')) {
            $this->components->warn('--prune has no effect without --orphans; nothing pruned.');
        }

        return $exit;
    }

    private function walkOrphans(BlobReconciler $reconciler, int $exit): int
    {
        $disk = $this->diskOption();
        $grace = $this->orphanGraceOption();
        $prune = (bool) $this->option('prune');

        $result = $reconciler->sweepOrphans(disk: $disk, graceHours: $grace, prune: $prune);

        // The active CAS driver cannot enumerate the disk (e.g. S3 /
        // non-FilesystemAdapter) — refuse the walk cleanly rather than fail; the
        // row-side reconcile result above still stands.
        if (!$result['listingSupported']) {
            $this->components->warn(
                'Orphan walk: the active CAS driver does not support listing — skipped.',
            );

            return $exit;
        }

        // --prune was asked for but this driver does not serialise the write-less
        // prune against a concurrent commit (SQLite): we DETECTED orphans but
        // refused to delete them. Tell the operator where pruning is safe.
        $pruneRefused = $prune && !$result['pruneSupported'];
        $effectivelyPruning = $prune && $result['pruneSupported'];

        $mode = $effectivelyPruning ? 'pruned' : 'report-only';

        $this->components->info(
            "Orphan walk ({$mode}): scanned {$result['scanned']}, found ".count($result['orphans']).
            ", pruned {$result['pruned']}, skipped {$result['skipped']} ".
            '(disk '.($disk ?? $this->mediaConfig->disk()).', grace '.$this->mediaConfig->orphanGraceHours($grace).'h).',
        );

        if ($pruneRefused) {
            $this->components->warn(
                'Orphan pruning needs PostgreSQL/MySQL (the delete must serialize against '.
                'concurrent commits); on sqlite use report-only or run on the production DB.',
            );
        }

        if ($result['orphans'] === []) {
            return $exit;
        }

        // Orphans surfaced but not deleted (report-only, or prune refused on this
        // driver) — list them and degrade the exit to FAILURE so cron/CI sees the
        // hazard (mirrors the dangling-row exit branch). A prune that cleaned them
        // all leaves $result['orphans'] non-empty yet pruned them; only the
        // not-actually-pruning paths fail the run.
        if (!$effectivelyPruning) {
            $this->components->warn(
                'Orphan objects (bytes with no blob row): '.implode(', ', $result['orphans']),
            );

            return self::FAILURE;
        }

        return $exit;
    }

    private function diskOption(): ?string
    {
        $value = $this->option('disk');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function orphanGraceOption(): int
    {
        $value = $this->option('orphan-grace');

        return is_numeric($value) ? (int) $value : 0;
    }
}
