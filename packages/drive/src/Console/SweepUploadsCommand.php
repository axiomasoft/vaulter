<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vaulter\Drive\Exceptions\UploadConflictException;
use Vaulter\Drive\Uploads\Repositories\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\UploadSessionManager;

/**
 * Sweep stale resumable-upload sessions (§2.8 / D16 / M3a). It scans the OPEN
 * sessions (status initiated|uploading) PLUS any session stuck in Assembled past its
 * TTL (F-P1.4-10 — commit interrupted after every byte landed) whose expires_at has
 * elapsed, and reclaims each through {@see UploadSessionManager::expire}: the
 * protocol-side state is released (S3 AbortMultipartUpload / per-part objects), the
 * staged temp under tmp/ is deleted, and the row is marked Expired. Schedulable.
 *
 * Pair with an S3 bucket lifecycle rule `AbortIncompleteMultipartUpload`: the sweeper
 * aborts the multiparts it KNOWS about (its v_upload_parts rows), and the lifecycle
 * rule is the backstop for any orphaned multipart the table never saw (the CAS bucket
 * itself stays UNVERSIONED — lifecycle is never a blob-deletion mechanism, H.3).
 */
final class SweepUploadsCommand extends Command
{
    protected $signature = 'vaulter:sweep-uploads';

    protected $description = 'Abort and clean up stale resumable-upload sessions past their TTL (§2.8).';

    public function handle(UploadSessionReadRepository $sessions, UploadSessionManager $manager): int
    {
        $now = Carbon::now();

        // Materialise first: expire() mutates the rows the cursor would walk.
        $candidates = $sessions->sweepable($now)->get();

        $swept = 0;
        $skipped = 0;

        foreach ($candidates as $session) {
            try {
                $manager->expire($session);
                $swept++;
            } catch (UploadConflictException) {
                // A tus PATCH holds this session's lock — it is being written to RIGHT
                // NOW, which is the one thing a "stale session" is not (VLT-REMED P5.2 /
                // R-02). Reclaiming it here would delete the staged bytes out from under
                // the in-flight append and corrupt the file. Leave it: it is either
                // finished by its client, or still past its TTL on the next tick and
                // swept then. Background housekeeping never destroys live data and never
                // fails the command over it.
                $skipped++;
            }
        }

        $this->components->info(sprintf(
            'Swept %d stale upload session%s%s.',
            $swept,
            $swept === 1 ? '' : 's',
            $skipped > 0 ? sprintf(' (%d skipped — a PATCH is in flight)', $skipped) : '',
        ));

        return self::SUCCESS;
    }
}
