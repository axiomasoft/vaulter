<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots\Mapping;

use Packman\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;

/** @internal */
#[Internal]
final readonly class FileRevisionSnapshotMapper
{
    public function map(FileRevision $revision, File $file): FileRevisionSnapshot
    {
        $id = SnapshotValue::id($revision->getKey());

        return new FileRevisionSnapshot(
            id: $id,
            fileNodeId: SnapshotValue::id($file->node_id),
            number: $revision->number,
            sizeBytes: $revision->size_bytes,
            sha256: $revision->sha256,
            mimeType: $revision->mime_type,
            declaredMimeType: $revision->declared_mime_type,
            scanStatus: ScanStatus::tryFrom($revision->scan_status) ?? ScanStatus::Failed,
            keepForever: (bool) $revision->getAttribute('keep_forever'),
            isHead: SnapshotValue::nullableId($file->current_revision_id) === $id,
            restoredFromRevisionId: SnapshotValue::nullableId($revision->restored_from_revision_id),
            clientModifiedAt: SnapshotValue::time($revision->client_modified_at),
            createdAt: SnapshotValue::requiredTime($revision->created_at),
            createdBy: SnapshotValue::nullableId($revision->created_by),
        );
    }
}
