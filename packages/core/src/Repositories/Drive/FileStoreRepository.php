<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Vaulter\Models\Drive\File;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_files — the mutable 1:1 handle for a kind=file node
 * (§2.4). Bare create + head/version-count bookkeeping. Opens NO transactions —
 * UploadFileAction wraps it.
 */
final class FileStoreRepository
{
    /** Read the aggregate and its revision counter under the caller's row lock. */
    public function lockForUpdate(File $file): File
    {
        $model = Config::fileModel();

        return $model::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): File
    {
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from UploadFileAction/CopyNodeAction, never raw request input.
        $model = Config::fileModel();
        $file = new $model;
        $file->forceFill($attributes);
        $file->save();

        return $file;
    }

    /**
     * Repoint the file head to a new current version and refresh the cached
     * mime/size, setting version_count to the allocated number (§2.4 commit flow).
     */
    public function repointHead(
        File $file,
        int|string $currentVersionId,
        ?string $mimeType,
        int $sizeBytes,
        int $versionNo,
    ): File {
        $file->forceFill([
            'current_version_id' => $currentVersionId,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'version_count' => $versionNo,
        ])->save();

        return $file;
    }
}
