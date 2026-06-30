<?php

declare(strict_types=1);

namespace Vaulter\Uploads;

use Illuminate\Validation\ValidationException;
use Vaulter\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Enums\Uploads\UploadProtocol;
use Vaulter\Models\Uploads\UploadSession;
use Vaulter\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Support\Config;

/**
 * The tus.io CORE protocol handler (§2.8 / D16 / M3a). It implements the three core
 * operations directly (no heavy tus framework — self-contained over Flysystem + our
 * session table) and works on ANY disk:
 *
 *   - Creation  → UploadSessionManager::initiate already minted the session + its
 *                 upload_id (the tus Location); initialise() here is a no-op (the
 *                 staged temp is created lazily on the first PATCH).
 *   - HEAD      → offset() returns the AUTHORITATIVE server-side received_bytes (the
 *                 tus Upload-Offset). A second client resuming reads THIS, never its
 *                 own guess — the resume point is server-owned (H.3).
 *   - PATCH     → patch() appends an application/offset+octet-stream chunk at the
 *                 declared offset (rejected unless it matches our offset), advances
 *                 received_bytes from the bytes that actually landed on the staged
 *                 temp, and reports whether the upload is now complete.
 *
 * The handler NEVER commits — when received_bytes == total it reports complete and
 * the controller drives CommitUploadAction (assemble→BlobStore.commit, H.3).
 */
final readonly class TusUploadHandler implements UploadProtocolHandler
{
    public function __construct(
        private StagedUpload $staged,
        private UploadSessionStoreRepository $sessions,
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
    public function initialise(UploadSession $session): array
    {
        return [];
    }

    /**
     * The tus HEAD Upload-Offset (§2.8): the authoritative server-side resume point.
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
     * Apply a tus PATCH (§2.8): append the chunk at $offset, advance received_bytes,
     * and report the new offset + whether the upload is complete. The offset MUST
     * match the server's current offset (a tus 409 Conflict otherwise) — the server,
     * not the client, decides where the bytes go.
     *
     * @param  resource                           $chunk
     * @return array{offset: int, complete: bool}
     */
    public function patch(UploadSession $session, mixed $chunk, int $offset): array
    {
        if (!$session->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => "Upload session {$session->upload_id} is not accepting chunks (status {$session->status->value}).",
            ]);
        }

        $serverOffset = $this->offset($session);

        if ($offset !== $serverOffset) {
            // tus 409 Conflict: the client's Upload-Offset does not match ours. The
            // controller maps this to a 409 so the client re-reads HEAD and resumes.
            throw ValidationException::withMessages([
                'offset' => "Upload-Offset {$offset} does not match the server offset {$serverOffset}.",
            ]);
        }

        $newSize = $this->staged->append(session: $session, chunk: $chunk, offset: $offset);

        $this->assertWithinMaxBytes(session: $session, size: $newSize);

        $this->sessions->advanceReceived(session: $session, receivedBytes: $newSize);

        return [
            'offset' => $newSize,
            'complete' => $this->reachedTotal($session, $newSize),
        ];
    }

    /**
     * Whether every declared byte has been received (§2.8). With a known total the
     * gate is received_bytes == total; with an unknown total the client signals
     * completion explicitly (a zero-length terminating PATCH), so a session whose
     * total is null is complete once it has received at least one byte and the client
     * marks it done — surfaced here as "received == total" only when total is set.
     */
    public function isComplete(UploadSession $session): bool
    {
        return $this->reachedTotal($session, $session->received_bytes);
    }

    /**
     * tus has no server-side multipart state to reclaim — the staged temp discard is
     * done by the manager. No-op.
     */
    public function abort(UploadSession $session): void
    {
        // Nothing protocol-side to release for tus.
    }

    private function reachedTotal(UploadSession $session, int $received): bool
    {
        $total = $session->total_size_bytes;

        return $total !== null && $received >= $total;
    }

    private function assertWithinMaxBytes(UploadSession $session, int $size): void
    {
        $max = Config::uploadMaxBytes();

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
