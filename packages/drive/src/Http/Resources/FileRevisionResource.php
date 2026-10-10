<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;

/**
 * An immutable revision of a file: its number, content digest, the scan verdict that
 * decides whether it may be downloaded, and whether retention keeps it forever.
 */
final class FileRevisionResource extends JsonResource
{
    public function __construct(FileRevisionSnapshot $revision)
    {
        parent::__construct($revision);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var FileRevisionSnapshot $revision */
        $revision = $this->resource;

        return [
            'id' => $revision->id,
            'number' => $revision->number,
            'size_bytes' => $revision->sizeBytes,
            'mime' => $revision->mimeType,
            'declared_mime' => $revision->declaredMimeType,
            'sha256' => $revision->sha256,
            'scan_status' => $revision->scanStatus->value,
            'keep_forever' => $revision->keepForever,
            'is_head' => $revision->isHead,
            'created_by' => $revision->createdBy,
            'created_at' => SnapshotValue::iso($revision->createdAt),
        ];
    }
}
