<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Drive\File;

/**
 * Write-side row work for v_files — the mutable 1:1 handle for a kind=file node
 * . Bare create + head/version-count bookkeeping. Opens NO transactions —
 * UploadFileAction wraps it.
 */
#[Internal]
final class FileStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /** Read the aggregate and its revision counter under the caller's row lock. */
    public function lockForUpdate(File $file): File
    {
        $model = $this->vaulterConfig->model('file');

        return $model::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): File
    {
        // forceFill: $attributes is a server-built literal array
        // from UploadFileAction/CopyNodeAction, never raw request input.
        $model = $this->vaulterConfig->model('file');
        $file = new $model;
        $file->forceFill($attributes);
        $file->save();

        return $file;
    }

    /**
     * Repoint the file head to a new current version and refresh the cached
     * mime/size, setting revision_count to the allocated number (commit flow).
     */
    public function repointHead(
        File $file,
        int|string $currentVersionId,
        ?string $mimeType,
        int $sizeBytes,
        int $versionNo,
    ): File {
        $file->forceFill([
            'current_revision_id' => $currentVersionId,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'revision_count' => $versionNo,
        ])->save();

        return $file;
    }

    /**
     * Drop the head pointer of a file about to be purged. MySQL checks the RESTRICT
     * head FK while the purge cascade deletes the revisions, before the file row
     * itself is gone; with no head there is nothing to check.
     */
    public function releaseHead(File $file): void
    {
        $file->forceFill(['current_revision_id' => null])->save();
    }
}
