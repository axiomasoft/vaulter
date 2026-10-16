<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Uploads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * A resumable-upload session: the status a client polls and the server-side offset it
 * resumes from. Neither the staged bytes, their storage key nor the blob the bytes were
 * committed to are part of it.
 *
 * @mixin UploadSession
 */
#[Internal]
final class UploadSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'upload_id' => $this->upload_id,
            'drive_id' => SnapshotValue::id($this->drive_id),
            'protocol' => $this->protocol->value,
            'status' => $this->status->value,
            // The resume point is always the server's offset, never the client's guess.
            'received_bytes' => $this->received_bytes,
            'total_size_bytes' => $this->total_size_bytes,
            'chunk_size' => $this->chunk_size,
            'mime_type' => $this->mime_type,
            'node_id' => SnapshotValue::nullableId($this->target_node_id),
            'target_parent_id' => SnapshotValue::nullableId($this->target_parent_id),
            'target_name' => $this->target_name,
            'expires_at' => SnapshotValue::iso($this->expires_at),
        ];
    }
}
