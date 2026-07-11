<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Repositories;

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Exceptions\UploadConflictException;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\UploadModels;

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
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from UploadSessionManager::initiate, never raw request input.
        $model = UploadModels::session();
        $session = new $model;
        $session->forceFill($attributes);
        $session->save();

        // Refresh so DB-default columns (received_bytes, status) are hydrated for the
        // caller (the manager returns the row straight back to the controller).
        return $session->refresh();
    }

    /**
     * Take the per-session lock as the FIRST statement of the tus PATCH transaction
     * and return the fresh, locked row (§2.8 / N3 / F-P1.4-01). The lock is held for
     * the whole "read offset → append → advance received_bytes" window so two
     * concurrent PATCH can never both read the same offset and clobber each other's
     * bytes. Its received_bytes/status are the authoritative values the handler must
     * act on — never the possibly-stale argument model (that would take the lock but
     * act on old data, defeating it).
     *
     *  - pgsql : a NON-BLOCKING advisory lock keyed by upload_id (the same
     *            pg_advisory_xact_lock seam BlobStore uses, in its try_ form). A
     *            PATCH that cannot take it because another is in-flight is REJECTED
     *            immediately with a conflict rather than made to wait — a retry storm
     *            must not pile up blocked requests (§N3). The lock releases at
     *            COMMIT/ROLLBACK.
     *  - mysql : SELECT … FOR UPDATE on the row (the portable pessimistic path).
     *  - sqlite: no row lock, but the single-writer transaction serialises the
     *            critical section, so the fresh read alone is correct.
     *
     * The same seam is taken by the sweeper/abort path (UploadSessionManager::expire /
     * abort, R-02) — reclaiming a session's staged bytes is the same critical section
     * as writing them, so the two can never interleave.
     *
     * The pgsql key is 64-bit (`hashtextextended`, R-09): the 32-bit `hashtext` shares
     * a key between two DIFFERENT upload_ids often enough to matter (birthday bound over
     * int4), and a collision there means a PATCH on session A is refused with a bogus 409
     * because an unrelated session B holds the lock.
     */
    public function acquireForPatch(UploadSession $session): UploadSession
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            // Non-blocking: pg_try_advisory_xact_lock returns false when another
            // PATCH already holds the upload_id lock → immediate conflict, no wait.
            $granted = $connection->selectOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS granted',
                ['upload:'.$session->upload_id],
            );

            if (!(is_object($granted) && ($granted->granted ?? false) === true)) {
                throw UploadConflictException::forSession($session->upload_id);
            }

            return $this->freshSession($session, lock: false);
        }

        // MySQL: a real FOR UPDATE row lock. SQLite: single-writer serialises, so the
        // fresh read is enough (lockForUpdate is a harmless no-op there anyway).
        return $this->freshSession($session, lock: $driver !== 'sqlite');
    }

    /**
     * Re-read the session row inside the current transaction, optionally FOR UPDATE.
     */
    private function freshSession(UploadSession $session, bool $lock): UploadSession
    {
        $model = UploadModels::session();
        $query = $model::query()->whereKey($session->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
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
     *
     * COMPARE-AND-SET on the status (VLT-REMED P5.2 / R-08): the write only lands while
     * the session is still OPEN. The previous unconditional `forceFill(status =>
     * Uploading)` could RESURRECT a session another actor had just moved to a terminal
     * state (Expired by the sweeper, Aborted by the client) — the row went back to
     * Uploading with a received_bytes that no longer described any real file. Zero rows
     * affected means someone else won the race → a conflict, not a silent overwrite.
     */
    public function advanceReceived(UploadSession $session, int $receivedBytes): UploadSession
    {
        $model = UploadModels::session();

        $affected = $model::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [UploadStatus::Initiated->value, UploadStatus::Uploading->value])
            ->update([
                'received_bytes' => $receivedBytes,
                'status' => UploadStatus::Uploading->value,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }

        return $session->refresh();
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
