<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * Read-only view of one resumable upload session at the moment it was taken. `id` is the session's own key and
 * `uploadId` the protocol handle a client resumes with; they are never the same value. The snapshot describes the
 * session, it is not its state: the drive re-reads and locks the current session by `id` before it writes, aborts or
 * completes, so a stale status or offset here never moves the upload.
 */
#[Api]
final readonly class UploadSessionSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $uploadId,
        public string $driveId,
        public ?string $tenantId,
        public UploadProtocol $protocol,
        public UploadStatus $status,
        public ?string $targetNodeId,
        public ?string $targetParentId,
        public ?string $targetName,
        public ?int $totalSizeBytes,
        public int $receivedBytes,
        public ?int $chunkSize,
        public ?string $mimeType,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    /** The snapshot of a current session row: its own tenant. */
    #[Internal]
    public static function fromModel(UploadSession $session): self
    {
        return self::of($session, $session->tenant_id);
    }

    /**
     * The snapshot of the session an operation answered with, which may be the one an idempotency receipt decoded: a
     * receipt does not keep the tenant, so it is named by the caller's trusted drive context, never guessed.
     */
    #[Internal]
    public static function fromResult(UploadSession $session, int|string|null $tenantId): self
    {
        return self::of($session, $tenantId);
    }

    private static function of(UploadSession $session, mixed $tenantId): self
    {
        return new self(
            id: SnapshotValue::id($session->getRawOriginal($session->getKeyName())),
            uploadId: $session->upload_id,
            driveId: SnapshotValue::id($session->drive_id),
            tenantId: SnapshotValue::nullableId($tenantId),
            protocol: $session->protocol,
            status: $session->status,
            targetNodeId: SnapshotValue::nullableId($session->target_node_id),
            targetParentId: SnapshotValue::nullableId($session->target_parent_id),
            targetName: $session->target_name,
            totalSizeBytes: $session->total_size_bytes,
            receivedBytes: $session->received_bytes,
            chunkSize: $session->chunk_size,
            mimeType: $session->mime_type,
            expiresAt: SnapshotValue::time($session->expires_at),
        );
    }
}
