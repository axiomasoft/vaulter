<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Uploads;

use Illuminate\Support\Str;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

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
    public static function storage(?int $quotaBytes = null): Drive
    {
        static $owner = 0;
        $owner++;

        // forceFill (P1.4/F-P1.8-02): owner_id/disk/used_bytes are excluded from
        // Storage's $fillable, and Node has its own closed list — this trusted
        // fixture builder bypasses both exactly like DriveStoreRepository/
        // NodeStoreRepository do.
        $storage = new Drive;
        $storage->forceFill([
            'owner_type' => 'team',
            'owner_id' => $owner,
            'name' => 'Disk',
            'disk' => 'local',
            'quota_bytes' => $quotaBytes,
            'used_bytes' => 0,
        ]);
        $storage->save();

        $root = new Node;
        $root->forceFill([
            'storage_id' => $storage->getKey(),
            'parent_id' => null,
            'kind' => NodeKind::Folder,
            'name' => '',
            'size_bytes' => 0,
        ]);
        $root->save();

        $storage->forceFill(['root_node_id' => $root->getKey()])->save();

        return $storage->refresh();
    }

    /**
     * Open a session row directly (bypassing the manager) for handler-level tests.
     */
    public static function session(
        Drive $storage,
        UploadProtocol $protocol = UploadProtocol::Tus,
        ?int $totalSizeBytes = null,
        ?string $targetName = 'upload.bin',
        int|string|null $parentId = null,
        int|string|null $nodeId = null,
        int|string|null $createdBy = null,
    ): UploadSession {
        // forceFill (P1.4/F-P1.8-02): storage_id/status/received_bytes/… are
        // excluded from UploadSession's $fillable — bypassed here exactly like
        // UploadSessionManager::initiate() does.
        $session = new UploadSession;
        $session->forceFill([
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
            'created_by' => $createdBy,
        ]);
        $session->save();

        return $session;
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
