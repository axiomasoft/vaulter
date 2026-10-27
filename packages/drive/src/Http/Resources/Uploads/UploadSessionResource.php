<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Uploads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;

/**
 * A resumable-upload session: the status a client polls and the server-side offset it
 * resumes from. Neither the staged bytes, their storage key nor the blob the bytes were
 * committed to are part of it.
 *
 * @mixin UploadSessionSnapshot
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
        $session = $this->resource;
        if (!$session instanceof UploadSessionSnapshot) {
            throw new LogicException('[Vaulter] An upload session resource renders an UploadSessionSnapshot.');
        }

        return [
            'upload_id' => $session->uploadId,
            'drive_id' => $session->driveId,
            'protocol' => $session->protocol->value,
            'status' => $session->status->value,
            // The resume point is always the server's offset, never the client's guess.
            'received_bytes' => $session->receivedBytes,
            'total_size_bytes' => $session->totalSizeBytes,
            'chunk_size' => $session->chunkSize,
            'mime_type' => $session->mimeType,
            'node_id' => $session->targetNodeId,
            'target_parent_id' => $session->targetParentId,
            'target_name' => $session->targetName,
            'expires_at' => SnapshotValue::iso($session->expiresAt),
        ];
    }
}
