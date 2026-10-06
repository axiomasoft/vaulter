<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Uploads;

use Illuminate\Database\QueryException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * Write-side row work for v_upload_sessions (§2.8 / M3a). Bare create + the
 * lifecycle transitions (status, received_bytes, assembled_blob_id). It opens NO
 * transactions — the UploadSessionManager / CommitUploadAction wrap it — and makes
 * no business decision: received_bytes is advanced from the bytes the caller already
 * staged, and the status moves are pure row writes.
 */
final class UploadSessionStoreRepository
{
    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DriveConfig $driveConfig,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): UploadSession
    {
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from UploadSessionManager::initiate, never raw request input.
        $model = $this->driveConfig->model('upload_session');
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
     * The lock is NON-BLOCKING on every engine that has one, because "I could not take
     * the lock" is a first-class ANSWER here, not a delay to sit out: a PATCH turns it
     * into an immediate 409 instead of piling up blocked requests behind a retry storm
     * (§N3), and the sweeper turns it into "skip, this session is alive" (R-02).
     *
     *  - pgsql : pg_try_advisory_xact_lock keyed by upload_id (the same advisory-lock
     *            seam BlobStore uses, in its try_ form). Releases at COMMIT/ROLLBACK.
     *  - mysql : SELECT … FOR UPDATE NOWAIT on the row (MySQL 8 / MariaDB 10.3, the
     *            engines the compatibility guide declares). A plain blocking
     *            lockForUpdate() here silently broke the contract above on MySQL alone
     *            (VLT-REMED P5.2): the sweeper never got its UploadConflictException, it
     *            WAITED on the PATCH's row lock instead and, at innodb_lock_wait_timeout,
     *            threw a raw QueryException — which SweepUploadsCommand does not catch, so
     *            one live upload aborted the whole scheduled sweep and left every remaining
     *            stale session unswept. NOWAIT fails instantly and is mapped, below, onto
     *            the same conflict the other engines raise.
     *  - sqlite: no row lock, but the single-writer transaction serialises the
     *            critical section, so the fresh read alone is correct.
     *
     * The same seam is taken by every OTHER actor that touches those staged bytes, which is
     * the whole point: the sweeper/abort path (UploadSessionManager::expire / abort, R-02)
     * and the commit path (UploadSessionManager::commitAssembled, R-11 — an Assembled
     * session is reclaimable, so streaming its bytes into BlobStore must exclude the sweeper
     * exactly as a PATCH does). Reclaiming a session's staged bytes, appending to them and
     * committing them are ONE critical section; none of the three can interleave.
     *
     * The pgsql key is 64-bit (`hashtextextended`, R-09): the 32-bit `hashtext` shares
     * a key between two DIFFERENT upload_ids often enough to matter (birthday bound over
     * int4), and a collision there means a PATCH on session A is refused with a bogus 409
     * because an unrelated session B holds the lock.
     */
    public function acquireForPatch(UploadSession $session): UploadSession
    {
        $connection = $this->database->connection();
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

            return $this->freshSession($session, lock: null);
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                return $this->freshSession($session, lock: 'for update nowait');
            } catch (QueryException $e) {
                if (!$this->isLockNotAvailable($e)) {
                    throw $e;
                }

                throw UploadConflictException::forSession($session->upload_id);
            }
        }

        // SQLite: single-writer serialises the critical section, so the fresh read is enough.
        return $this->freshSession($session, lock: null);
    }

    /**
     * Re-read the session row inside the current transaction, optionally under a lock
     * clause (a raw clause, not lockForUpdate(): NOWAIT has no builder shorthand).
     */
    private function freshSession(UploadSession $session, ?string $lock): UploadSession
    {
        $model = $this->driveConfig->model('upload_session');
        $query = $model::query()->whereKey($session->getKey());

        if ($lock !== null) {
            $query->lock($lock);
        }

        return $query->firstOrFail();
    }

    /**
     * Did MySQL/MariaDB refuse the row lock because someone else holds it — as opposed to
     * failing for a real reason (missing table, dead connection), which must NOT be dressed
     * up as a conflict and swallowed by the sweeper.
     *
     *  - 3572 ER_LOCK_NOWAIT      — MySQL 8: "lock(s) could not be acquired immediately".
     *  - 1205 ER_LOCK_WAIT_TIMEOUT — what MariaDB raises for the same NOWAIT refusal, and
     *    what a host that pinned an engine without NOWAIT would hit on its lock timeout.
     */
    private function isLockNotAvailable(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return $code === 3572 || $code === 1205;
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
        $model = $this->driveConfig->model('upload_session');

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
     *
     * COMPARE-AND-SET on Assembled (VLT-REMED P5.2 / R-11) — the last writer that was still
     * a plain forceFill after R-08 gave one to advanceReceived(). It closes the same
     * resurrection hole on the OTHER end of the lifecycle: `scopeSweepable` includes an
     * Assembled session past its TTL, so a sweeper may reclaim the row (staged bytes
     * discarded, status → Expired) while the commit is streaming those very bytes into
     * BlobStore — and the unconditional forceFill then declared that terminal, byte-less
     * session Committed, with an assembled_blob_id pointing at whatever the CAS happened to
     * hash. Zero rows affected means someone else won → a conflict, never a silent
     * overwrite; the commit transaction rolls back with it.
     */
    public function markCommitted(UploadSession $session, int|string $assembledBlobId): UploadSession
    {
        $model = $this->driveConfig->model('upload_session');

        $affected = $model::query()
            ->whereKey($session->getKey())
            ->where('status', UploadStatus::Assembled->value)
            ->update([
                'assembled_blob_id' => $assembledBlobId,
                'status' => UploadStatus::Committed->value,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }

        return $session->refresh();
    }
}
