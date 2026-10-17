<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\FileRevision;

/**
 * Write-side row work for the immutable v_file_revisions. Bare create +
 * the supersede transition for a prior head. Opens NO transactions —
 * UploadFileAction wraps it. number is monotonic per file (the unique index
 * is the backbone); the caller allocates numbers from the locked file counter.
 */
#[Internal]
final class FileRevisionStoreRepository
{
    public function __construct(
        private readonly DriveConfig $vaulterConfig,
        private readonly VaulterDatabase $database,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): FileRevision
    {
        // forceFill: $attributes is a server-built literal array
        // from UploadFileAction/CopyNodeAction, never raw request input.
        $model = $this->vaulterConfig->model('file_revision');
        $version = new $model;
        $version->forceFill($attributes);
        $version->save();

        return $version;
    }

    /**
     * Mark the prior head version superseded when a newer version takes
     * over. The row stays — only the lifecycle status changes, and superseded_at
     * starts the non-current age the retention policy counts.
     */
    public function markSuperseded(FileRevision $version): FileRevision
    {
        $version->forceFill(['status' => 'superseded', 'superseded_at' => now()])->save();

        return $version;
    }

    /** Pin or unpin a revision: keep_forever is the only column of a revision that changes after its insert. */
    public function setKeepForever(FileRevision $version, bool $keepForever): FileRevision
    {
        $version->forceFill(['keep_forever' => $keepForever])->save();

        return $version;
    }

    /**
     * Delete a non-current revision row. Restores copied from it forget their source
     * first (the FK does the same where it exists; SQLite upgrades have no FK), and
     * the edge rows and scan rows go with it by cascade. The caller detached the
     * blobs and released the quota under the file and revision locks.
     */
    public function delete(FileRevision $version): void
    {
        $this->database->connection()->table($version->getTable())
            ->where('restored_from_revision_id', $version->getKey())
            ->update(['restored_from_revision_id' => null]);
        $version->delete();
    }
}
