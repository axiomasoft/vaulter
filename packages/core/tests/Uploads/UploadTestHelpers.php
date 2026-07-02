<?php

declare(strict_types=1);

namespace Vaulter\Tests\Uploads;

use Illuminate\Support\Str;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Storage;

/**
 * Shared builders for the resumable-upload slice tests (M3a): a real Storage with a
 * root folder, plus a quick session factory and a chunk-stream helper.
 */
final class UploadTestHelpers
{
    /**
     * A fresh storage with a kind=folder root node (the mount the upload targets).
     * One per call (the owner-unique index forbids two storages for one owner+panel).
     */
    public static function storage(?int $quotaBytes = null): Storage
    {
        static $owner = 0;
        $owner++;

        $storage = Storage::query()->create([
            'owner_type' => 'team',
            'owner_id' => $owner,
            'name' => 'Disk',
            'disk' => 'local',
            'quota_bytes' => $quotaBytes,
            'used_bytes' => 0,
        ]);

        $root = $storage->nodes()->create([
            'storage_id' => $storage->getKey(),
            'parent_id' => null,
            'kind' => NodeKind::Folder,
            'name' => '',
            'size_bytes' => 0,
        ]);

        $storage->forceFill(['root_node_id' => $root->getKey()])->save();

        return $storage->refresh();
    }

    /**
     * Open a session row directly (bypassing the manager) for handler-level tests.
     */
    public static function session(
        Storage $storage,
        UploadProtocol $protocol = UploadProtocol::Tus,
        ?int $totalSizeBytes = null,
        ?string $targetName = 'upload.bin',
        int|string|null $parentId = null,
        int|string|null $nodeId = null,
    ): UploadSession {
        return UploadSession::query()->create([
            'storage_id' => $storage->getKey(),
            'upload_id' => (string) Str::uuid(),
            'node_id' => $nodeId,
            'target_parent_id' => $parentId,
            'target_name' => $targetName,
            'protocol' => $protocol,
            'status' => UploadStatus::Initiated,
            'total_size_bytes' => $totalSizeBytes,
            'received_bytes' => 0,
            'mime_type' => 'application/octet-stream',
        ]);
    }

    /**
     * An in-memory read stream over the given bytes.
     *
     * @return resource
     */
    public static function streamFor(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
