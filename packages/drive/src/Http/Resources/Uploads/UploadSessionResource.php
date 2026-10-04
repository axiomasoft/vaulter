<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Uploads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * The read shape of a resumable-upload session (§2.8 / D16 / M3a): the status the
 * client polls and the authoritative server-side offset it resumes from. Read-only —
 * it transforms an already-loaded UploadSession, never queries. The raw bytes / the
 * staged temp key are NEVER exposed.
 *
 * @mixin UploadSession
 */
final class UploadSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'upload_id' => $this->upload_id,
            'protocol' => $this->protocol->value,
            'status' => $this->status->value,
            // The authoritative resume point (tus Upload-Offset). The client never
            // resumes from its own guess — this is the server's offset (H.3).
            'received_bytes' => $this->received_bytes,
            'total_size_bytes' => $this->total_size_bytes,
            'chunk_size' => $this->chunk_size,
            'mime_type' => $this->mime_type,
            'node_id' => $this->target_node_id,
            'target_parent_id' => $this->target_parent_id,
            'target_name' => $this->target_name,
            // Set only once the assembled bytes committed through BlobStore.commit.
            'assembled_blob_id' => $this->assembled_blob_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
