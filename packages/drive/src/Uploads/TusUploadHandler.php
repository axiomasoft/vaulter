<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Illuminate\Validation\ValidationException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Support\LoadTier;

/**
 * The tus.io CORE protocol handler. It implements the three core
 * operations directly (no heavy tus framework — self-contained over Flysystem + our
 * session table) and works on ANY disk:
 *
 *   - Creation  → UploadSessionManager::initiate already minted the session + its
 *                 upload_id (the tus Location); initialise() here is a no-op (the
 *                 staged temp is created lazily on the first PATCH).
 *   - HEAD      → offset() returns the AUTHORITATIVE server-side received_bytes (the
 *                 tus Upload-Offset). A second client resuming reads THIS, never its
 *                 own guess — the resume point is server-owned.
 *   - PATCH     → patch() appends an application/offset+octet-stream chunk at the
 *                 declared offset (rejected unless it matches our offset), advances
 *                 received_bytes from the bytes that actually landed on the staged
 *                 temp, and reports whether the upload is now complete.
 *
 * The handler NEVER commits — when received_bytes == total it reports complete and
 * the controller drives CommitUploadAction (assemble→BlobStore.commit).
 */
#[Internal]
final readonly class TusUploadHandler implements UploadProtocolHandler
{
    public function __construct(
        private StagedUpload $staged,
        private UploadSessionStoreRepository $sessions,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private DriveConfig $driveConfig,
        private UploadSessionReadRepository $reads,
    ) {}

    public function protocol(): UploadProtocol
    {
        return UploadProtocol::Tus;
    }

    /**
     * Creation is handled by the manager (the session + upload_id already exist).
     * There is no eager staged temp — it is created on the first PATCH.
     *
     * @return array<string, mixed>
     */
    public function initialise(UploadSessionSnapshot $session): array
    {
        return [];
    }

    /**
     * The tus HEAD Upload-Offset: the authoritative server-side resume point.
     * Reconciled against the staged temp's actual length so a crashed PATCH (bytes
     * landed but the row update lost) never reports an offset past the real bytes.
     */
    public function offset(UploadSession $session): int
    {
        $onDisk = $this->staged->size($session);
        $recorded = $session->received_bytes;

        // The smaller of the two is the safe resume point: we only trust bytes that
        // both landed on disk AND were recorded.
        return min($onDisk, $recorded);
    }

    /**
     * Apply a tus PATCH: append the chunk at $offset, advance received_bytes,
     * and report the new offset + whether the upload is complete. The offset MUST
     * match the server's current offset (a tus 409 Conflict otherwise) — the server,
     * not the client, decides where the bytes go.
     *
     * @param  resource                           $chunk
     * @return array{offset: int, complete: bool}
     */
    public function patch(UploadSession $session, mixed $chunk, int $offset): array
    {
        try {
            return $this->metrics->timing(
                name: 'vaulter_upload_chunk_duration_seconds',
                operation: fn (): array => $this->patchChunk(session: $session, chunk: $chunk, offset: $offset),
                labels: [
                    'protocol' => 'tus',
                    'tier' => LoadTier::label(),
                ],
            );
        } catch (UploadConflictException $exception) {
            $this->metrics->counter(
                name: 'vaulter_upload_conflict_total',
                labels: ['protocol' => 'tus'],
            );

            throw $exception;
        }
    }

    /**
     * @param  resource                           $chunk
     * @return array{offset: int, complete: bool}
     */
    private function patchChunk(UploadSession $session, mixed $chunk, int $offset): array
    {
        // The whole read-modify-write of received_bytes runs inside ONE transaction
        // that takes a row lock on the session as its FIRST statement (
        // N3). Without it two concurrent PATCH read the same server offset, both pass
        // the offset check and both append — silently corrupting the assembled file.
        // The lock is held until advanceReceived() so the window is indivisible; the
        // acquire is NOWAIT so a concurrent PATCH is rejected as a conflict, never
        // left to pile up waiting.
        return $this->database->transaction(function () use ($session, $chunk, $offset): array {
            $locked = $this->sessions->acquireForPatch($session);

            if (!$locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => "Upload session {$locked->upload_id} is not accepting chunks (status {$locked->status->value}).",
                ]);
            }

            // The authoritative offset read from the LOCKED, fresh row — never the
            // possibly-stale argument model (that would take the lock but act on old
            // data, defeating it).
            $serverOffset = $this->offset($locked);

            if ($offset !== $serverOffset) {
                // tus C6 (N3): offset < server is ONLY an idempotent retransmit when the
                // resent bytes match what is already staged in that range — the client
                // repeated a chunk it already sent (a retry after a lost response), not
                // a genuine desync. Anything else (a byte mismatch, or offset > server —
                // a "jump ahead") is a real 409 Conflict: no bytes are
                // appended, no other state moves.
                if ($offset < $serverOffset && $this->staged->rangeMatches($locked, $chunk, $offset)) {
                    return [
                        'offset' => $serverOffset,
                        'complete' => $this->reachedTotal($locked, $serverOffset),
                    ];
                }

                // tus 409 Conflict: the client's Upload-Offset does not match ours. The
                // exception self-renders 409 + the authoritative Upload-Offset
                // so the client re-reads HEAD and resumes — never a bare 302/422.
                $this->metrics->counter(
                    name: 'vaulter_upload_conflict_total',
                    labels: ['protocol' => 'tus'],
                );

                throw new OffsetMismatchException($serverOffset);
            }

            $newSize = $this->staged->append(
                session: $locked,
                chunk: $chunk,
                offset: $offset,
                declaredTotal: $locked->total_size_bytes,
                maxBytes: $this->driveConfig->maxFileSize(),
            );
            $acceptedBytes = $newSize - $serverOffset;

            if ($acceptedBytes > 0) {
                $this->metrics->counter(
                    name: 'vaulter_upload_bytes_total',
                    amount: $acceptedBytes,
                    labels: ['protocol' => 'tus'],
                );
            }

            $this->assertWithinMaxBytes(session: $locked, size: $newSize);

            $this->sessions->advanceReceived(session: $locked, receivedBytes: $newSize);

            return [
                'offset' => $newSize,
                'complete' => $this->reachedTotal($locked, $newSize),
            ];
        });
    }

    /**
     * Whether every declared byte has been received. With a known total the
     * gate is received_bytes == total; with an unknown total the client signals
     * completion explicitly (a zero-length terminating PATCH), so a session whose
     * total is null is complete once it has received at least one byte and the client
     * marks it done — surfaced here as "received == total" only when total is set.
     */
    public function isComplete(UploadSessionSnapshot $session): bool
    {
        $current = $this->current($session);

        return $this->reachedTotal($current, $current->received_bytes);
    }

    /**
     * tus has no server-side multipart state to reclaim — the staged temp discard is
     * done by the manager. No-op.
     */
    public function abort(UploadSessionSnapshot $session): void
    {
        // Nothing protocol-side to release for tus.
    }

    /** The current session behind a snapshot: its status, totals and offset are the ones decided on. */
    private function current(UploadSessionSnapshot $session): UploadSession
    {
        return $this->reads->find($session->id) ?? throw NodeNotFoundException::forId('upload');
    }

    private function reachedTotal(UploadSession $session, int $received): bool
    {
        $total = $session->total_size_bytes;

        return $total !== null && $received >= $total;
    }

    private function assertWithinMaxBytes(UploadSession $session, int $size): void
    {
        $max = $this->driveConfig->maxFileSize();

        if ($max === 0 || $size <= $max) {
            return;
        }

        // Overrun past the ceiling mid-stream: reject and let the controller surface
        // it. The staged temp is reclaimed when the session is aborted/swept.
        throw ValidationException::withMessages([
            'received_bytes' => "The upload exceeded the maximum allowed {$max} bytes.",
        ]);
    }
}
