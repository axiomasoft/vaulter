<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Drive\FileVersion;

/**
 * The read shape of an immutable file version (§2.4): the monotonic version_no,
 * its content address and the upload metadata. Read-only — it transforms an
 * already-loaded FileVersion, never queries.
 *
 * @mixin FileVersion
 */
final class FileVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var FileVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->getKey(),
            'file_id' => $version->file_id,
            'version_no' => $version->version_no,
            'content_sha256' => $version->content_sha256,
            'size_bytes' => $version->size_bytes,
            'mime_type' => $version->mime_type,
            'original_name' => $version->original_name,
            'status' => $version->status,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at,
            'committed_at' => $version->committed_at,
        ];
    }
}
