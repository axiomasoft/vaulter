<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Uploads;

use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * The small per-protocol strategy behind the resumable-upload session. Two implementations ship:
 *   - TusUploadHandler   — the tus.io CORE protocol (Creation + HEAD + PATCH), works
 *                          on ANY Flysystem disk;
 *   - S3MultipartHandler — the object-store multipart protocol (initiate / sign or
 *                          proxy parts / complete), only on S3-style disks.
 *
 * Every handler's job is the SAME: accumulate the bytes (resumably) onto the staged
 * temp, advancing the authoritative server-side received_bytes (never the client's
 * claim), and report when the session is fully assembled. The assemble→commit step
 * is OUTSIDE the handler (CommitUploadAction → BlobStore.commit) so the invariant
 * — assembled bytes ALWAYS go through the one race-free CAS, server-rehashed — holds
 * identically for both protocols.
 */
interface UploadProtocolHandler
{
    /**
     * Which protocol this handler speaks (so the manager can pick it by session).
     */
    public function protocol(): UploadProtocol;

    /**
     * Initialise the protocol-side state for a freshly created session — e.g.
     * S3 CreateMultipartUpload, or a no-op for tus (the staged temp is created lazily
     * on the first PATCH). Returns any protocol metadata to surface to the client
     * (presigned part URLs, the multipart UploadId, …).
     *
     * @return array<string, mixed>
     */
    public function initialise(UploadSession $session): array;

    /**
     * Whether the session has received all of its bytes — for tus, received_
     * bytes == total_size_bytes; for S3 multipart, the parts cover the declared
     * total. The manager flips the session to Assembled when this is true.
     */
    public function isComplete(UploadSession $session): bool;

    /**
     * Abort the protocol-side state — e.g. S3 AbortMultipartUpload, or a no-op
     * for tus. The staged temp is discarded by the manager separately, so this only
     * reclaims object-store-side multipart state. Idempotent.
     */
    public function abort(UploadSession $session): void;
}
