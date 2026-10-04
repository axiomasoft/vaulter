<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Vaulter\Models\Drive\FileRevision;
use Vaulter\Support\Config;

/**
 * Write-side row work for the immutable v_file_revisions (§2.4). Bare create +
 * the supersede transition for a prior head. Opens NO transactions —
 * UploadFileAction wraps it. number is monotonic per file (the unique index
 * is the backbone); the caller allocates numbers from the locked file counter.
 */
final class FileRevisionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): FileRevision
    {
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from UploadFileAction/CopyNodeAction, never raw request input.
        $model = Config::fileVersionModel();
        $version = new $model;
        $version->forceFill($attributes);
        $version->save();

        return $version;
    }

    /**
     * Mark the prior head version superseded (§2.4) when a newer version takes
     * over. The row stays — only the lifecycle status changes.
     */
    public function markSuperseded(FileRevision $version): FileRevision
    {
        $version->forceFill(['status' => 'superseded'])->save();

        return $version;
    }
}
