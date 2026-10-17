<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Uploads;

use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Media\Contracts\Cas\ContentScanner;

/**
 * Shared builders for the resumable-upload slice tests (M3a): a real Storage with a
 * root folder, plus a quick session factory and a chunk-stream helper.
 */
final class UploadTestHelpers
{
    /**
     * The host's scanner: the capability reads the class from `vaulter-drive.scan.scanner` and the container
     * hands out this very object.
     */
    public static function scanner(ContentScanner $scanner): ContentScanner
    {
        config()->set('vaulter-drive.scan.scanner', $scanner::class);
        app()->instance($scanner::class, $scanner);
        app()->instance(ContentScanner::class, $scanner);

        return $scanner;
    }

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
        app(VaulterDatabase::class)->write(fn () => $storage->save());

        $root = new Node;
        $root->forceFill([
            'drive_id' => $storage->getKey(),
            'parent_id' => null,
            'kind' => NodeKind::Folder,
            'name' => '',
            'size_bytes' => 0,
        ]);
        app(VaulterDatabase::class)->write(fn () => $root->save());

        app(VaulterDatabase::class)->write(fn () => $storage->forceFill(['root_node_id' => $root->getKey()])->save());

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
        // forceFill (P1.4/F-P1.8-02): drive_id/status/received_bytes/… are
        // excluded from UploadSession's $fillable — bypassed here exactly like
        // UploadSessionManager::initiate() does.
        $session = new UploadSession;
        $session->forceFill([
            'drive_id' => $storage->getKey(),
            'upload_id' => (string) Str::uuid(),
            'target_node_id' => $nodeId,
            'target_parent_id' => $parentId,
            'target_name' => $targetName,
            'protocol' => $protocol,
            'status' => UploadStatus::Initiated,
            'total_size_bytes' => $totalSizeBytes,
            'received_bytes' => 0,
            'mime_type' => 'application/octet-stream',
            'created_by' => $createdBy,
        ]);
        app(VaulterDatabase::class)->write(fn () => $session->save());

        return $session;
    }

    /** @return array<string,mixed> */
    public static function clientMetadata(UploadSession $session): array
    {
        return array_diff_key($session->metadata ?? [], array_flip(['actor_type', 'expected_sequence']));
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

    /**
     * Point a session at a drive row that does not exist: the corrupt state every engine's foreign key refuses. The
     * check is lifted for this one write only — PostgreSQL drops the constraint (it cannot be switched off inside the
     * test transaction), SQLite defers it to a commit RefreshDatabase never makes, MySQL turns FOREIGN_KEY_CHECKS off.
     */
    public static function danglingDrive(UploadSession $session, int $missingDriveId = 9_999_999): void
    {
        $connection = $session->getConnection();
        $table = $session->getTable();
        $write = static fn () => UploadSession::query()->whereKey($session->getKey())->update(['drive_id' => $missingDriveId]);
        match ($connection->getDriverName()) {
            'pgsql' => (static function () use ($connection, $table, $write): void {
                $connection->statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.$table.'_drive_id_foreign"');
                $write();
            })(),
            'sqlite' => (static function () use ($connection, $write): void {
                $connection->statement('PRAGMA defer_foreign_keys = ON');
                $write();
            })(),
            default => $connection->getSchemaBuilder()->withoutForeignKeyConstraints($write),
        };
    }
}
