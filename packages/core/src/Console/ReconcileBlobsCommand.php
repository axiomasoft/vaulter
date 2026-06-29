<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Cas\BlobReconciler;

/**
 * Rebuild the ref_count cache from the v_file_version_blobs edge counts — the real
 * liveness truth (H.3) — and report integrity hazards: dangling rows (a committed
 * blob whose canonical object is missing on disk). Orphan objects (bytes on disk
 * with no row) need a disk walk, which is disk-specific and left to the operator;
 * this command flags the row-side hazards and the corrected counts.
 */
final class ReconcileBlobsCommand extends Command
{
    protected $signature = 'vaulter:reconcile-blobs';

    protected $description = 'Rebuild blob ref_count from edge counts and report dangling rows (H.3)';

    public function handle(BlobReconciler $reconciler): int
    {
        $report = $reconciler->reconcile();

        $this->components->info(
            "Reconciled {$report['reconciled']} blobs, corrected {$report['changed']} ref_count caches.",
        );

        if ($report['dangling'] !== []) {
            $this->components->warn(
                'Dangling rows (committed blob, missing object): '.implode(', ', $report['dangling']),
            );

            return self::FAILURE;
        }

        $this->components->info('No dangling rows detected.');

        return self::SUCCESS;
    }
}
