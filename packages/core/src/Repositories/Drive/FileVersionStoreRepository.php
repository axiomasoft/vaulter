<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Support\Config;

/**
 * Write-side row work for the immutable v_file_versions (§2.4). Bare create +
 * the supersede transition for a prior head. Opens NO transactions —
 * UploadFileAction wraps it. version_no is monotonic per file (the unique index
 * is the backbone); nextVersionNo reads the current max under the caller's
 * transaction.
 */
final class FileVersionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): FileVersion
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
     * The next monotonic version_no for a file (§2.4) — current max + 1, or 1 for
     * the first version. Read inside the caller's transaction so two concurrent
     * uploads serialise on the unique (file_id, version_no) index.
     */
    public function nextVersionNo(File $file): int
    {
        $model = Config::fileVersionModel();

        $max = $model::query()
            ->where('file_id', $file->getKey())
            ->max('version_no');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    /**
     * Mark the prior head version superseded (§2.4) when a newer version takes
     * over. The row stays — only the lifecycle status changes.
     */
    public function markSuperseded(FileVersion $version): FileVersion
    {
        $version->forceFill(['status' => 'superseded'])->save();

        return $version;
    }
}
