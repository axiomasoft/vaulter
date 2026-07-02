<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Repositories;

use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_upload_sessions (§2.8 / M3a). Bare create + the
 * lifecycle transitions (status, received_bytes, assembled_blob_id). It opens NO
 * transactions — the UploadSessionManager / CommitUploadAction wrap it — and makes
 * no business decision: received_bytes is advanced from the bytes the caller already
 * staged, and the status moves are pure row writes.
 */
final class UploadSessionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): UploadSession
    {
        $model = Config::uploadSessionModel();
        $session = new $model;
        $session->fill($attributes);
        $session->save();

        // Refresh so DB-default columns (received_bytes, status) are hydrated for the
        // caller (the manager returns the row straight back to the controller).
        return $session->refresh();
    }

    /**
     * Move the session to a new lifecycle status (§2.8). The legal transition is the
     * caller's responsibility (the manager guards it via UploadStatus::canTransitionTo).
     */
    public function transition(UploadSession $session, UploadStatus $status): UploadSession
    {
        $session->forceFill(['status' => $status])->save();

        return $session;
    }

    /**
     * Advance the authoritative server-side received_bytes offset after a tus PATCH
     * chunk has been appended (§2.8). The session flips to Uploading on the first
     * write. The offset is SET (not incremented) from the staged temp's new length,
     * so a re-sent identical chunk is idempotent.
     */
    public function advanceReceived(UploadSession $session, int $receivedBytes): UploadSession
    {
        $session->forceFill([
            'received_bytes' => $receivedBytes,
            'status' => UploadStatus::Uploading,
        ])->save();

        return $session;
    }

    /**
     * Stamp the assembled blob id + flip to Committed once the assembled bytes have
     * gone through BlobStore.commit (§2.8 / H.3). The single writer of
     * assembled_blob_id.
     */
    public function markCommitted(UploadSession $session, int|string $assembledBlobId): UploadSession
    {
        $session->forceFill([
            'assembled_blob_id' => $assembledBlobId,
            'status' => UploadStatus::Committed,
        ])->save();

        return $session;
    }
}
